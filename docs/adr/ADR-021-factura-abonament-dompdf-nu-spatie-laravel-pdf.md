# ADR-021: The subscription invoice stays on `DompdfInvoiceRenderer`, the Cashier default — not on `spatie/laravel-pdf`

- **Status**: Accepted
- **Date**: 2026-09-19
- **Deciders**: project owner
- **Partially supersedes**: [[ADR-006]] — exclusively the sentence "Generating subscription invoice PDFs uses `spatie/laravel-pdf`, not `dompdf` — the change introduced in Cashier 16." The rest of [[ADR-006]] remains in force, untouched: Cashier 16 on API `2025-06-30.basil`, `Billable` = the tenant (the organization, not the user), webhook idempotency through `webhook_events` / uniqueness on `event_id`.
- **Related**: [[ADR-005]] (customer invoices, a separate flow), [[ADR-013]] (heavy calls/processes not in the HTTP request), [[ADR-019]] (where the memory budget was measured and where the discrepancy was first flagged)
- **Tags**: stripe, cashier, pdf, memory, phase-5

## Context and problem statement

[[ADR-006]] states: "Generating subscription invoice PDFs uses `spatie/laravel-pdf`, not `dompdf` — the change introduced in Cashier 16." The claim is a misreading, already flagged on 2026-09-14 in the closing note of [[ADR-019]] ("ADR-006 does not match the installed code") and explicitly left as "the owner's decision, at the latest in Phase 5" — that is, now.

What the installed code says, as opposed to the assumption:

- `vendor/laravel/cashier/config/cashier.php:107` — `'renderer' => env('CASHIER_INVOICE_RENDERER', DompdfInvoiceRenderer::class)`. Cashier 16 **added** `LaravelPdfInvoiceRenderer` as an option, not as the default — the default stays `DompdfInvoiceRenderer`.
- Cashier's config is not published in the project (`config/cashier.php` does not exist outside `vendor/`), and `CASHIER_INVOICE_RENDERER` is set nowhere (not even in `.env.example`). The renderer actually in effect today is therefore `DompdfInvoiceRenderer` — exactly what ADR-006 says is NOT being used.
- If we switched to the renderer [[ADR-006]] wrongly describes as already active, `vendor/laravel/cashier/src/Invoices/LaravelPdfInvoiceRenderer.php` calls `Pdf::html(...)->format($paper)->toResponse(...)`, **without** `->driver('dompdf')` — that is, on the default driver of the `spatie/laravel-pdf` package, which is `browsershot` (Chromium), not DomPDF.
- [[ADR-019]] measured and documented that the production image **has no Chromium or Node at runtime**, on a VPS with a 250-400 MB budget and 11 OOM kills already measured (`.ai/rules/project.md`). Downloading a subscription invoice on that renderer would have failed at runtime, not at build time.
- The project's own code already chooses the driver **explicitly**, never by environment default: `app/Support/Exports/PdfExporter.php:55` and `app/Support/Reports/ReportFileWriter.php:57` call `Pdf::view(...)->driver('dompdf')`, with a docblock saying why ("the package default stays free for the Phase 5 invoices, which can choose another driver without touching the list export" — `PdfExporter`). `dompdf/dompdf` ^3.0 is a direct dependency since [[ADR-019]].

Phase 5 (plan §11) is now building the actual Stripe subscription — the first code that will touch downloading a subscription invoice on the `Billable` entity. The decision can no longer stay open.

## Decision drivers

- **The memory budget is fixed, it is not negotiated upwards** (`.ai/rules/project.md`) — Chromium at runtime was already rejected, with measurements, in [[ADR-019]].
- **"If a solution requires one more service, the default answer is no"** (`.ai/rules/project.md`) — this holds for a binary/Node process added to the image too, not only for a new container.
- **Driver chosen per call, never by environment default** — the convention already established by `PdfExporter`/`ReportFileWriter`: a missing environment variable on a new environment (Coolify, CI, a development machine) must not be able to silently flip behaviour from "works" to "fails at runtime".
- **Zero new code, zero new infrastructure**, if the alternative brings no measurable benefit.
- **Honesty in the ADR, not silence** — if the decision leaves two entry points into the same library, it is stated explicitly, not hidden under a promised and unrealized "unification".

## Considered options

### Option 1: Stay on the Cashier default, `DompdfInvoiceRenderer` (CHOSEN)

`config/cashier.php` is not published, `CASHIER_INVOICE_RENDERER` is not set. Cashier renders the subscription invoice with its own template, through `dompdf/dompdf` directly — with no involvement of `spatie/laravel-pdf` at all.

- **Pro**: zero code, zero new config, zero environment risk — the behaviour is the package's own, already active and stable. `dompdf/dompdf` is already a direct dependency ([[ADR-019]]), so it adds nothing new to `composer.json`. No risk of a new environment failing at runtime for want of an environment variable.
- **Con**: two entry points into DomPDF coexist in the project — Cashier's (its own template, not controlled by our code) and `spatie/laravel-pdf` with an explicit driver (the customer invoices from [[ADR-005]], the exports and reports from [[ADR-019]]). The "unification" promised in `docs/adr/README.md` does not happen.

### Option 2: `CASHIER_INVOICE_RENDERER=LaravelPdfInvoiceRenderer` + `LARAVEL_PDF_DRIVER=dompdf`

This would unify on a single entry point — `spatie/laravel-pdf` everywhere — exactly as the original (mistaken) wording in [[ADR-006]] promised.

- **Con, decisive**: it moves the driver choice onto an **environment default**. `LaravelPdfInvoiceRenderer` (see the extract above) does not specify `->driver()` in code — it depends strictly on `config('laravel-pdf.driver')`/`LARAVEL_PDF_DRIVER`. If the variable is missing on a new environment (Coolify, CI, a development machine without a complete `.env`), rendering falls back to the package default, `browsershot`/Chromium — which is not in the image — and the failure shows up at **runtime** (the first click on "download invoice"), not at build time. It directly contradicts the rule `PdfExporter` already applies explicitly in code rather than through an environment variable.
- **Pro**: a single entry point into PDF for everything Stripe/subscription related + invoices + exports + reports, provided the environment is always configured correctly.

Rejected: the risk is exactly the silent, environment-dependent failure pattern the rest of the project avoids by construction (`.ai/rules/tenancy.md` documents a similar pattern for per-request memoization).

### Option 3: No local subscription PDF — only the invoices hosted by the Stripe Customer Portal

- **Pro**: it removes the problem — Stripe renders and hosts the PDF, the application no longer calls any local renderer.
- **Con**: it loses the in-app download for a portfolio demo, where that screen demonstrates the Cashier integration. Rejected.

### Option 4: Chromium in the production image

- **Con**: already rejected, with measurements, in [[ADR-019]] (a 150-250 MB peak on a container already near its cap, plus extra attack surface and image weight). The argument is not repeated here.

## Decision outcome

**Option 1.** The subscription invoice renderer stays the Cashier default, `DompdfInvoiceRenderer`. `config/cashier.php` is not published. `CASHIER_INVOICE_RENDERER` is not set in any environment. The cost is zero code and zero new infrastructure.

The accepted consequence, stated explicitly: the project has **two entry points** into the same library (DomPDF):

1. Cashier's, with its own invoice template, called through `DompdfInvoiceRenderer`, with no involvement of our own code;
2. `spatie/laravel-pdf`, with the driver chosen explicitly (`->driver('dompdf')`) in `PdfExporter` and `ReportFileWriter`, for the list exports ([[ADR-019]]), the PDF reports and the customer invoices ([[ADR-005]]).

Phase 5 builds the Stripe subscription: the code of that phase **does not switch the renderer** — it does not publish `config/cashier.php`, does not set `CASHIER_INVOICE_RENDERER`, does not add `->driver()` to anything related to downloading the subscription invoice. If the need for a direct download from our own code ever appears, it goes through the Cashier API as such, not through `PdfExporter`.

## Consequences

### Positive

- Zero code, zero published config, zero new environment variable — downloading a subscription invoice works identically on every environment (dev, CI, production), without depending on a default value.
- It removes exactly the risk Option 2 would have introduced: an environment-dependent runtime failure on the business path that touches the tenant's money.
- Consistent with the memory budget and with the absence of Chromium/Node from the production image ([[ADR-019]]).
- It corrects the discrepancy flagged in [[ADR-019]] publicly, without rewriting [[ADR-006]] — respecting the "an accepted ADR is never rewritten" rule (`.ai/rules/project.md`).

### Negative / trade-offs, accepted

- The "Unifying PDF generation" note in `docs/adr/README.md` does not close in the direction it originally assumed (all PDFs on `spatie/laravel-pdf`) — it closes in the opposite direction: two entry points remain, by decision, not by oversight.
- The subscription invoice template (`Invoice::view()`, from Cashier) is not under the same styling control as our own templates (`exports.pdf.list`, `reports.pdf.built-in`) — any visual customization of the Stripe invoice goes through publishing Cashier's views, not through `PdfExporter`.
- If a future Cashier version changes the default, or if `LaravelPdfInvoiceRenderer` gains a way to receive the driver explicitly (not only from `env()`), Option 2 deserves a re-evaluation — it is not rejected for good, it is rejected **for as long as** choosing it would depend on an environment variable that has no default.

## Links

- [[ADR-006]] — the base decision (Cashier 16, `Billable` = the tenant, webhook idempotency), partially superseded by this ADR on the PDF rendering sentence alone.
- [[ADR-005]] — customer invoices, a separate flow, already using `spatie/laravel-pdf` with an explicit driver.
- [[ADR-013]] — heavy calls/processes not in the HTTP request; DomPDF stays pure PHP, with no external process, so it does not raise the same problem.
- [[ADR-019]] — where the memory budget was measured, where `dompdf/dompdf` was installed, and where the discrepancy with [[ADR-006]] was first flagged.

## History

- 2026-09-19 (Phase 5) — created. The owner confirmed Option 1 out of the four above, closing the discrepancy flagged in [[ADR-019]] on 2026-09-14.
