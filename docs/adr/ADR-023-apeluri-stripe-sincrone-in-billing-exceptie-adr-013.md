# ADR-023: Two synchronous Stripe calls in `BillingController` — a narrow, named exception to ADR-013

- **Status**: Accepted
- **Date**: 2026-09-23
- **Deciders**: project owner
- **Related**: [[ADR-013]] (the rule this ADR narrowly excepts, amended with a pointer to this one), [[ADR-014]] (the middleware that makes the exception's cost real), [[ADR-021]] (precedent for documenting an accepted trade-off honestly), [[ADR-006]] (Cashier 16, the base decision), [[ADR-020]] ("a second/third caller needs its own ADR" precedent)
- **Tags**: performance, stripe, cashier, transactions, queues, phase-5

## Context and problem statement

[[ADR-013]] states, without conditions: "no call to an external service executes inside an HTTP request," because [[ADR-014]]'s `SetSessionContext` middleware wraps every authenticated request in a single Postgres transaction, open from `auth` to the response. Two methods on `BillingController` break that rule knowingly: `portal()` (`POST /settings/billing/portal`) and `invoiceHistory()` (called only from `index()`, `GET /settings/billing`). Both were already documented, before this ADR, as a "conscious deviation from ADR-013" directly in their docblocks. The audit (PERF-02, 2026-09-23) confirmed the deviation is real and flagged the missing formal exception: "ADR-013 says 'absolute rule' with no conditions ... no ADR excepts it."

**Verified against the request pipeline**, not assumed: both routes sit inside `Route::middleware(['auth', 'session.context'])` and then `['workspace', 'subscription.access']` (`routes/web.php:64,76`, `routes/web/billing.php`). `session.context` is `SetSessionContext`, and its own docblock says as much: "Opens the request's transaction ... the transaction stays open for the whole duration of the request, so no external call is allowed in a controller" (`app/Http/Middleware/SetSessionContext.php:13-20`). The implementation confirms the comment — `SetSessionContext::handle()` calls `TenantContext::openFor()`, which wraps `$next($request)` in `DB::transaction()` (`app/Services/Tenancy/TenantContext.php:29-38`). So yes: both Stripe calls execute inside the same request-long transaction the audit describes — a measured fact of the current code, not a theoretical risk.

One distinction matters and keeps the description honest: neither method **writes** to the database before calling Stripe — both are pure reads (`portal()` reads the tenant already bound by `ResolveWorkspace`; `invoiceHistory()` only maps Cashier's response). This differs from the write-then-call sequence ADR-013 was written for (a shipment row inserted before the carrier call, an invoice row inserted before Chromium). So the risk here is not accumulated row locks — it is occupying one of `pm.max_children=4` PHP-FPM workers and one of Postgres's `max_connections=30` for the full round-trip to Stripe, on every visit to this screen.

**Why no queue is a workable substitute for either call:**

- `portal()` returns a one-time, Stripe-hosted URL (`$sessionsService->create(...)['url']`, `vendor/laravel/cashier/src/Concerns/ManagesCustomer.php:605-616`) that the browser must follow immediately, via a full navigation — `Inertia::location()`, not a normal Inertia visit, because the destination is `billing.stripe.com`/`checkout.stripe.com`, a different origin (see the controller's own docblock for why `redirect()` would break). There is no "pending" state to poll for, unlike a shipping label or an invoice PDF: the button's entire job is "send me to Stripe right now." A queued job could create the session, but the browser would still have to wait synchronously for that job to finish before it has anywhere to go — the wait relocates, it does not disappear.
- `invoiceHistory()` renders the real list Stripe holds. Cashier's `invoices()`/`invoicesIncludingPending()` (`vendor/laravel/cashier/src/Concerns/ManagesInvoices.php:324-363`) *is* what "invoice history" means on this screen — there is no local mirror to read instead ([[ADR-005]] keeps customer invoicing local and separate, but that ledger is Throughput's own AR toward its customers, not Stripe's subscription invoices toward Throughput; the two are not interchangeable data). Cashier itself makes **zero** network calls when the tenant has no `stripe_id` yet — `invoices()` checks `hasStripeId()` before any request (same file, line 326) — which covers every seeded/demo tenant in this batch and never touches the network. For a tenant that does have a `stripe_id`, `invoiceHistory()` wraps the call in `try/catch (Throwable)`, reports it, and degrades to an empty list rather than letting a failed Stripe hop turn this `GET` into a 500.

**Rejected: `Inertia::defer()` on the invoice list.** It would move the Stripe round-trip out of the page's first response, but the deferred prop is still resolved inside its own HTTP request, which goes through the same `session.context` middleware and therefore opens the same kind of request-long transaction again — the synchronous call inside an open transaction does not disappear, it relocates to a second request. What it would buy is a faster first paint of the page shell. For a Settings screen an Owner visits occasionally (§7.4, Owner-only), not a high-traffic list, that gain does not justify the added frontend complexity (a loading/empty/error state for a deferred prop, a second round trip through the whole middleware stack) — flagged and rejected here, not silently skipped.

## Decision drivers

- **Precision over a blanket rule.** [[ADR-013]]'s own reasoning is about calls that *can* leave the request because a "pending" state exists to show meanwhile (shipment creation, PDF generation). Applying it unmodified to a call whose entire purpose is an immediate redirect stretches the rule past what it was written to solve.
- **Honesty in the ADR, not silence** — the same standard [[ADR-021]] set: stating an accepted trade-off explicitly, with the measured facts, instead of leaving it as an in-code comment that only a code reader would ever see.
- **No exception grows without a boundary.** The exception has to name exactly which two methods it covers, or a future controller could point at "Stripe calls are fine" instead of at its own argument.
- **Frequency is a real mitigating factor ADR-013 does not dismiss** — Settings/Billing is Owner-only (§7.4), a low-traffic screen by construction, unlike the checkout/shipping paths ADR-013 targets.

## Considered options

### Option 1: Keep both calls synchronous, inside the request transaction — a narrow, named exception (CHOSEN)

- **Pro**: zero new code, zero new failure mode, matches exactly what each call needs (an immediate redirect target for `portal()`; the real Stripe list for a read page in `invoiceHistory()`).
- **Con**: both calls still occupy a PHP-FPM worker and a Postgres connection for the Stripe round-trip; under concurrency on this screen, that is a real, if currently unmeasured, constraint.

### Option 2: `Inertia::defer()` for `invoiceHistory()` (REJECTED)

- Moves perceived latency (a faster shell) but does not remove the synchronous call — the deferred request opens its own instance of the same request-long transaction. Adds a loading/empty/error state on the frontend for a screen visited rarely. Does not apply to `portal()` at all: there is no prop to defer when the entire response is a redirect.

### Option 3: Close the request transaction early, re-open the tenant context after the Stripe call (REJECTED)

- Considered because it would keep the letter of ADR-013. Rejected: it contradicts "`TenantContext` is the only gate" ([[ADR-014]]) — every query after a manual `DB::commit()` would need the RLS context re-established by hand, in exactly the two places most likely to be forgotten during a future refactor. The audit flagged this same option with the same verdict ("fragile, contradicts the single-gate rule").

### Option 4: A real queue + polling for both calls (REJECTED)

- Rejected outright for `portal()`: there is no "pending" screen state to show while Stripe issues a redirect URL — the browser has nowhere to go until the job finishes, so the user would wait *synchronously* for an *async* job, strictly worse than waiting for the HTTP response directly. For `invoiceHistory()` it would work mechanically (the same pattern already used for shipping labels and invoice PDFs, [[ADR-013]]), but it adds an intermediate "loading invoices…" state to a screen that renders once, rarely, in exchange for shortening a transaction that, under normal Stripe latency, is already short. Not proportionate for this decision; revisit per the criteria below if that changes.

## Decision outcome

**Option 1.** `BillingController::portal()` and `BillingController::invoiceHistory()` (reached only from `index()`) are the exception to [[ADR-013]]'s rule — and the *only* two methods it covers. Both keep their Stripe call inside the request's open transaction, for the structural reasons above (no async equivalent for a redirect; no local mirror for a read page), not out of neglect.

[[ADR-013]] is amended with a pointer to this ADR (its Status line and a note under the metadata block) — its own text, rule and status stay untouched, per the project's "an accepted ADR is never rewritten" convention.

## Consequences

### Positive

- Both flows keep doing exactly what they need to do, with no added state machine, no polling component, and no second request for a screen that does not need one.
- `invoiceHistory()`'s existing `try/catch` already prevents a Stripe hiccup from becoming a 500 on the read path — degrading to "currently unavailable" is honest to the user, not a crash.
- The exception is narrow and named, so [[ADR-013]] and `.ai/rules/project.md`'s "absolute rule" line stay meaningful for every other controller. Nobody can point at this ADR to justify a third synchronous external call.

### Negative / trade-offs, accepted

- Every visit to `GET /settings/billing`, and every click of "Manage billing" / "Reactivate," occupies one of `pm.max_children=4` PHP-FPM workers and one of Postgres's `max_connections=30` for the full duration of a Stripe round-trip. Unlike the cases [[ADR-013]] was written for, no row lock accumulates first — the cost is connection/worker occupancy, not lock contention. Under concurrency, a slow or throttled Stripe response holds that slot longer: the same "no error, just long transactions visible only under concurrency" pattern [[ADR-013]] describes for the cases it does cover.
- **No configured timeout ceiling exists for either call.** `config/cashier.php` stays unpublished ([[ADR-021]]); `config/services.php` carries no Stripe entry; no `setTimeout()`/`setConnectTimeout()` call exists anywhere in the app. The effective ceiling is therefore `stripe-php`'s own default (`vendor/stripe/stripe-php/lib/HttpClient/CurlClient.php:164-165`): `DEFAULT_CONNECT_TIMEOUT = 30`s, `DEFAULT_TIMEOUT = 80`s. Worst case, a single request can hold its PHP-FPM worker and Postgres connection for up to roughly 80 seconds waiting on Stripe. Whether PHP's own execution-time limit would intervene sooner was not checked — no override exists in `docker/app/` for either `max_execution_time` or `request_terminate_timeout`, so the platform default applies; verifying which one wins is flagged here, not resolved by this ADR.
- **The two methods are not symmetric today.** `invoiceHistory()` catches `Throwable` and degrades gracefully; `portal()` has no `try/catch` — `assertCustomerExists()` throws `Laravel\Cashier\Exceptions\InvalidCustomer` (uncaught) for a tenant with no `stripe_id` yet, and a network failure on the `billingPortal->sessions->create()` call itself would propagate the same way. The frontend does not gate the "Manage billing" button on the tenant having a subscription (`resources/js/Pages/Settings/Billing/Index.tsx:130-136` — the button renders whenever `can.manage` is true), so this path is reachable, not merely theoretical. This ADR documents the asymmetry honestly; it does not resolve it — a `try/catch` here would be a code change, outside a documentation-only exception, and is left for whoever picks up hardening this controller next. **Closed 2026-09-23**: `portal()` now catches `Throwable` around `billingPortalUrl()`, the same shape as `invoiceHistory()` — `report($e)`, a warning log, and a translated flash error back on the Billing page instead of a 500.
- Settings/Billing being Owner-only and low-traffic (§7.4) is why this is accepted *today*; it is not a guarantee it stays cheap if usage patterns change — see the criteria below.

### When to revisit this exception

- If Settings/Billing traffic or concurrency grows past occasional Owner visits (permissions widen to more roles, or usage data shows concurrent hits), re-measure the connection/worker occupancy accepted unmeasured here, and re-weigh Option 4 for `invoiceHistory()` specifically.
- If Stripe or Cashier ever ships a documented, safe way to narrow the request transaction around a single external call without hand-rolled context re-establishment (Option 3's rejection reason), re-evaluate against that mechanism.
- If a third controller wants the same exemption, it does not get one by pointing here — it needs its own ADR, with its own "no async equivalent" argument verified against its own code, per [[ADR-020]]'s "a second/third caller needs an ADR" precedent.

## Links

- [[ADR-013]] — the rule this ADR narrowly excepts; amended with a pointer to this ADR, not rewritten.
- [[ADR-014]] — the mechanism that makes the exception's cost real: `SetSessionContext` opens the request-long transaction.
- [[ADR-021]] — precedent for documenting an accepted trade-off explicitly; also the reason `config/cashier.php` stays unpublished, hence no timeout override exists.
- [[ADR-006]] — Cashier 16, `Billable` = the tenant; the base decision the two calls in this ADR build on.
- [[ADR-020]] — precedent for "a second/third caller needs its own ADR," applied here to any future synchronous external call.
- `app/Http/Controllers/Web/Settings/BillingController.php:59-117` — the two methods; docblocks updated to point here instead of restating the argument inline.
- `app/Http/Middleware/SetSessionContext.php`, `app/Services/Tenancy/TenantContext.php:29-38` — where the request-long transaction actually opens.
- `routes/web/billing.php`, `routes/web.php:64,76` — confirms both routes sit inside the `session.context` middleware group.
- `vendor/laravel/cashier/src/Concerns/ManagesCustomer.php:605-616` (`billingPortalUrl()`, `assertCustomerExists()`), `vendor/laravel/cashier/src/Concerns/ManagesInvoices.php:324-363` (`invoices()`/`invoicesIncludingPending()`), `vendor/stripe/stripe-php/lib/HttpClient/CurlClient.php:164-165` (default timeouts).
- `docs/reviews/2026-09-23_audit/02-performanta.md` (PERF-02) — the audit finding this ADR formalizes.

## History

- 2026-09-23 — created, formalizing the exception the code already documented in-line (audit finding PERF-02). The owner confirmed Option 1, naming exactly the two methods above and no others.
- 2026-09-23 — closed the asymmetry flagged under "Negative / trade-offs, accepted": `portal()` now has the same `try/catch (Throwable)` shape as `invoiceHistory()`.
