# ADR-006: Laravel Cashier 16 for the tenant subscription

- **Status**: Accepted — **the sentence about invoice PDF rendering is partially superseded by [[ADR-021]]**
- **Date**: 2026-09-12
- **Deciders**: Owner
- **Related**: [[ADR-005]] (the boundary between the two money flows), [[ADR-021]] (rendering the subscription invoice PDF — correction)
- **Tags**: stripe, cashier, subscription, sprint-4

> **Correction, note extended 2026-09-20 (read together with [[ADR-021]]).** This document says twice that subscription invoice PDFs go through `spatie/laravel-pdf`: once in the decision outcome, and once more at the very end, in the trade-offs — "To be unified on `spatie/laravel-pdf`." [[ADR-021]] names only the first of the two in its supersede clause, so the second sentence was left standing and still read as a plan.
>
> **Both are superseded.** The renderer in force is `DompdfInvoiceRenderer`, the Cashier 16 default, and the coexistence of two entry points into DomPDF — Cashier's own for the subscription invoice, `spatie/laravel-pdf` with an explicit driver for customer invoices ([[ADR-005]]) and exports ([[ADR-019]]) — is a deliberate outcome, not a pending clean-up. There is nothing left to unify.
>
> **The rest of this ADR remains fully in force**: Cashier 16 on API `2025-06-30.basil`, `Billable` = the tenant rather than the user, and webhook idempotency through `webhook_events` keyed on `event_id`.

## Context and problem statement

The `travel` project in the same portfolio chose the raw `stripe/stripe-php` SDK and rejected Cashier. The natural question: does the same precedent apply here?

**It does not.** `travel` had one-off payments for guest-type bookings, with no `Billable` model and no subscriptions — exactly the case where Cashier brings in migrations and a webhook flow that go unused. Throughput has recurring subscriptions per organization, which is precisely the case Cashier exists for.

## Decision outcome

**Laravel Cashier 16**, on Stripe API version `2025-06-30.basil`.

The `Billable` entity is **the tenant (the organization), not the user** — each workspace has its own Stripe Customer and its own subscription, managed by the Owner role. It is the research recommendation for B2B SaaS and the only one that makes sense when several users share a plan.

Generating subscription invoice PDFs uses `spatie/laravel-pdf`, not `dompdf` — the change introduced in Cashier 16.

Webhook idempotency stays ours, through the `webhook_events` table with a uniqueness constraint on `event_id`: **Stripe guarantees at-least-once delivery, never exactly-once.**

## Consequences

### Positive

- The billing portal, plan changes and history come out of the box.
- A subscription at the organization level is the correct model both for the demo and for reality.

### Negative / trade-offs

- A dependency on a package that follows Stripe's release cadence; a major API change may require a migration.
- Two PDF mechanisms in the project if the customer invoices (ADR-005) end up using something else. To be unified on `spatie/laravel-pdf`.
