# ADR-013: External calls leave the HTTP request and move to queues

- **Status**: Accepted — **narrow exception for two calls, see [[ADR-023]]**
- **Date**: 2026-09-12
- **Deciders**: Owner
- **Related**: [[ADR-003]] (the transaction comes from the isolation mechanism), [[ADR-010]] (shipping)
- **Tags**: performance, transactions, queues, postgresql, sprint-5

> **Exception, 2026-09-23 — [[ADR-023]].** The rule below stays in force for every other controller and every other external call. `BillingController::portal()` and `BillingController::invoiceHistory()` are a documented, narrowly-named exception — neither has an async equivalent (an immediate Stripe redirect; a read page that needs Stripe's real invoice list). See [[ADR-023]] for the full argument, the measured cost, and the criteria for revisiting it. This note only points there; it does not restate or relax the rule.

## Context and problem statement

[[ADR-003]] requires `SET LOCAL app.tenant_id` on every request, and `SET LOCAL` exists **only** inside a transaction. The literal implementation — the `ApplyTenantContext` middleware — wraps the whole of `$next($request)` in a `DB::transaction()`.

The consequence is not obvious: **any synchronous external call from a controller executes with an open Postgres transaction.** The specification describes two such calls:

- **creating a shipment** calls the carrier adapter and waits for `tracking_number` (§11.2, step 4);
- **generating an invoice PDF** starts an ephemeral Chromium through `spatie/laravel-pdf` — the plan estimates 150–250 MB and a few seconds (§3.1).

On a container with `shared_buffers=64MB` and `max_connections=30`, a transaction held open for several seconds, with the row locks accumulated up to that point, is a real problem under concurrency. It does not show up with a single developer; it shows up when two visitors ship at the same time.

The audit (P2-003) flagged it and proposed **narrowing the transaction** down to the queries themselves.

## Decision drivers

- **Under RLS, every query needs the context.** You cannot move reads out of the transaction without getting zero rows — which makes narrowing far less simple than it looks.
- **A user must not wait on Chromium.** Three seconds of waiting on "Generate invoice" is a bad experience independently of any transaction problem.
- **Visible progress is the demo's cheapest "wow moment"**, per the research. One more place where it appears is a gain, not a cost.

## Considered options

### Option 1: Narrowing the transaction (the audit's proposal)

- **Pro**: attacks the symptom directly; no change of flow.
- **Con**: under RLS, the context is needed on **every** query, including the reads inside the controller. Narrowing requires either a second strategy for setting the context on reads, or manual discipline in every action — exactly the kind of rule someone breaks six months later, silently.

### Option 2: External calls leave the request, into queues (CHOSEN)

- **Pro**: the transaction stays short by construction, not by discipline. The request responds immediately. Chromium leaves the critical path. One more place with visible progress.
- **Con**: the flow becomes asynchronous — the interface has to show an intermediate state ("Generating…") and poll.

## Decision outcome

**Option 2.** No call to an external service executes inside an HTTP request.

- **Shipping label**: `CreateShipmentAction` persists the shipment with `status = label_pending` and queues `GenerateShippingLabelJob`. The job calls the adapter ([[ADR-010]]), then opens a **short** transaction only to write `tracking_number` and `label_url`. The interface polls, as with bulk operations.
- **Invoice PDF**: the same — `invoices.pdf_status` (`pending` / `ready` / `failed`), a generation job, a download button that only becomes active when it is ready.

The rule, written as such in the plan: **if an action calls something that is not our own database, that action belongs in a queue.**

## Consequences

### Positive

- Transactions stay on the order of milliseconds, whatever the state of an external service.
- A carrier sandbox that is down no longer holds row locks — the job fails and is retried.
- Two more places where the demo shows live progress.
- Chromium no longer competes with PHP-FPM for memory during a request.

### Negative / trade-offs

- Two flows become asynchronous, with an intermediate state to display. They reuse the polling component already built for bulk operations (Phase 3), so the cost is in the interface, not in the architecture.
- A failed job has to be visible, otherwise the user waits forever. Both states have an explicit `failed`, with a message and a retry button.
- **There is only one queue worker** (`maxProcesses: 1`, see P3-004). Labels and PDFs share a queue with imports and reports. Queue prioritization already exists; the latency measurement remains to be done before launch.
