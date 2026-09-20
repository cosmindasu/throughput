# ADR-019: List PDF export (Orders) with DomPDF, not with Chromium in the image

- **Status**: Accepted
- **Date**: 2026-09-14
- **Deciders**: Owner
- **Related**: [[ADR-013]] (generation stays in a queue, never in the HTTP request), [[ADR-006]] (subscription invoices through Cashier 16 — see the discrepancy in the closing note)
- **Tags**: pdf, exports, memory, phase-3

## Context and problem statement

specs.md §13.5 asks, on Orders, for "CSV/PDF export, bulk cancel (`draft` only), owner reassignment"; plan §9 (Phase 3) has as an explicit deliverable "bulk CSV/PDF export working on Orders". The list export mechanism already exists (`ExportableResources` → `ListExport` → `ExportListJob`, on the `bulk` queue, ADR-013), but today it only exports CSV, for Accounts and Contacts (specs.md §13.2, §13.5). Orders is the first resource that also asks for PDF.

`spatie/laravel-pdf` (2.13.1) is a direct dependency since Sprint 0 (plan §6, task 3), intended for invoices and reports. Its config is not published, so the default driver is the package's own, `browsershot` (`LARAVEL_PDF_DRIVER`), which renders through Chromium. No code path calls it yet. Plan §3.1, justifying the 384m of the `horizon` container, says: "PDF jobs (`spatie/laravel-pdf`, invoices + reports) may launch an ephemeral Chromium process (150-250 MB) — the cap covers a peak, not the average." That note was written with the invoices (Phase 5) and the reports (Phase 4) in mind, not with the Phase 3 list export, which is now arriving first.

`docker/app/Dockerfile` confirms that the production image **has no Chromium or Node at runtime**: the `node:22-alpine` stage (`assets`) only compiles `public/build` through Vite and is not copied into the `production` stage beyond that static directory. Using `browsershot` for the Orders export would require either a Chromium binary added to the `production` image (extra weight + attack surface) or a persistent Node process — neither of them a single config line, both a change to the image.

`vendor/spatie/laravel-pdf/src/Drivers/DomPdfDriver.php` already exists, but it requires `dompdf/dompdf` (^3.0), which was **not** installed. The package enters `composer.json` together with this decision (3.1.6 installed, `composer audit` clean) — the only new dependency, pure PHP. It is, on top of that, exactly the package `laravel/cashier` 16.8 uses by default for the subscription invoice (`DompdfInvoiceRenderer`, Cashier's config unpublished).

## Decision drivers

- **The memory budget** (`.ai/rules/project.md`): 250-400 MB at peak, a VPS shared with 11 OOM kills already measured. `horizon` has 384m, already partly taken by the rest of the queue (`imports`, `reports`, `bulk`, with a single worker — plan §3).
- **"If a solution requires one more service, the default answer is no"** (project.md) — Gotenberg would introduce exactly that.
- **The production image has no Chromium/Node at runtime** — adding one is a `Dockerfile` change, not a driver choice inside a `->driver()`.
- **A list export is a long table, not a document with a complex layout** — exactly the case where DomPDF's limitations (CSS 2.1, no JS) cost nothing visible, unlike an invoice with graphic flourishes.
- **ADR-013 remains in force whatever the driver**: generation cannot hold the request's transaction open — it has to be a queued job, with the driver as an implementation detail inside that job.
- **Invoices and reports are not decided here** — the plan assumes Chromium for them (§3.1); a premature driver decision "for all PDF in the project" would wrongly couple two different use cases.

## Considered options

### Option 1: DomPDF, via `spatie/laravel-pdf` (CHOSEN)

- **Pro**: pure PHP, no external process, no binary and no new service. The production image stays unchanged. It runs inside the already-budgeted `horizon` worker, with no separate process peak. The same library Cashier 16.8 uses by default for subscription invoices.
- **Con**: one new dependency in `composer.json`. CSS 2.1 (no flex/grid) — the template has to be built as a simple table. Slow, with growing memory use, on very long tables — hence the row cap. Limited fonts (DejaVu included, it covers Romanian diacritics). No JavaScript.

### Option 2: Chromium in the image (Browsershot / Chrome PHP)

- **Pro**: faithful rendering (modern CSS, complex layout); it would also serve the Phase 5 invoices through the same mechanism.
- **Con**: a 150-250 MB peak on the single Horizon worker (plan §3.1), on a container already near its cap with the demo reset (ADR-017); a larger production image; Chromium at runtime on a VPS with an OOM history is exactly the risk the memory budget is meant to avoid.

### Option 3: Gotenberg

- **Pro**: rendering through Chromium, isolated in a dedicated container, with no Node in the application image.
- **Con**: one more container — it directly contradicts the "one more service = no" rule (project.md) and adds a new memory cap on a VPS already at the limit.

### Option 4: WeasyPrint

- **Pro**: better CSS rendering than DomPDF (partial flex support), without Chromium.
- **Con**: a Python binary in the image — a new system dependency, absent today from `docker/app/Dockerfile`, for a use case (a long table) that does not need it.

### Option 5: Cloudflare Browser Rendering

- **Pro**: no process or binary in the image, and a driver already available in `spatie/laravel-pdf` (`CloudflareDriver`).
- **Con**: a new external sub-processor, absent from specs.md §28.2 — a recurring cost, and order data (customer names, addresses) sent outside our own infrastructure for an operation that does not need it. It would also require updating the sub-processor list and, probably, the privacy policy.

### Option 6: Deferring PDF to Phase 5

- **Pro**: zero new code now.
- **Con**: the Phase 3 deliverable from plan §9 ("bulk CSV/PDF export working on Orders") would stay partial for no real technical reason — DomPDF covers the case today, with a single pure-PHP dependency.

## Decision outcome

**Option 1.** The list PDF export (first case: Orders, Phase 3) uses the `dompdf` driver of `spatie/laravel-pdf`, chosen **explicitly at each call** (`Pdf::view(...)->driver('dompdf')->save($path)`), **without changing** `config('laravel-pdf.driver')`/`LARAVEL_PDF_DRIVER` (which stays `browsershot`, the package default). Choosing per call rather than globally keeps the driver decision open for the customer invoices (Phase 5) and for the PDF reports (Phase 4) — neither is decided by this ADR.

- **Job**: `ExportListJob` (or its direct successor, once it gains a format parameter) stays on the `bulk` queue, outside the request's transaction — the ADR-013 rule does not depend on the driver.
- **Row cap for the PDF format**: a new config key, `limits.export_pdf_max_rows` (env `EXPORT_PDF_MAX_ROWS`), **default 500**. It started at "on the order of 1,000" and came down after the first measurement: `PdfExporter` run in isolation, on the development machine, produced 97 MB / 0.5 s at 100 rows, 227 MB / 2.8 s at 500, 347 MB / 5.3 s at 750 and 499 MB / 8.3 s at 1,000. The growth is not linear, because of DomPDF's table layout. At 1,000 rows the peak exceeds `horizon`'s 384m cap. The figure is still to be re-confirmed on the production image (the same pattern as §7.10 and as ADR-017: "measured, not assumed"). Above the cap, the PDF format is refused with an explicit message that points to CSV (no format is turned off — CSV stays available up to `export_sync_max_rows`/`bulk_max_rows`, which already exist); no new cap on CSV.
- **The `dompdf.is_remote_enabled` config** stays `false` (the package default) — the export templates load no remote resources (images, fonts from an external URL), only inline/local CSS 2.1.
- **The job's memory peak** is measured at the row cap, on the production image, before launch. If it exceeds `horizon`'s budget (384m, already partly taken by the rest of the concurrent queue — imports/reports/bulk), **the row cap goes down**, the container's budget does not go up (project.md: the budget is fixed; what gets negotiated is the work that fits inside it, not the limit).

## Consequences

### Positive

- Zero new services, zero `Dockerfile` change, zero new system dependency; a single pure-PHP library in `composer.json`.
- Predictable memory: pure PHP, inside the already-budgeted worker, with no external process peak stacked on top of the rest of the `bulk` queue.
- The driver decision for invoices (Phase 5) and reports (Phase 4) stays entirely open — DomPDF is now an option already present in the project for either of them.
- Consistent with ADR-013: no "heavy" call enters the HTTP request, whatever the driver.

### Negative / trade-offs

- CSS 2.1 — no flex/grid — the export PDF template has to stay tabular and simple; a designer expecting the fidelity of a modern-CSS export will not find it here.
- A new row cap to explain in the UI: a large export remains possible, but only in CSV. The cost is one more rule visible to the user, not a silent limitation.
- DomPDF is slow and its memory use grows on very long tables, which is exactly the reason for the cap. The first measurement (from 97 MB at 100 rows to 499 MB at 1,000) brought the cap down from "on the order of 1,000" to 500. Until it is re-confirmed on the production image, 500 is a development-machine figure, not a production one.
- The note already in `docs/adr/README.md` ("Unifying PDF generation") stays open and is extended by this case; it is not closed by this ADR.

## Note for the plan/specs (to be entered in the Change Log by the owner)

- **plan-implementare.md §3.1**, the justification for `horizon`'s 384m, says generically "PDF jobs (`spatie/laravel-pdf`, invoices + reports) may launch an ephemeral Chromium process (150-250 MB)". From this ADR on, the Orders list export (Phase 3) does **not** fall under that description — it uses DomPDF, in process, without Chromium. The line stays correct for invoices (Phase 5) and reports (Phase 4) **only if** those also choose Chromium; that is not guaranteed — DomPDF is now an option for them as well. The table in §3.1 will have to be reconciled at the latest when that decision is made (Phase 4 for reports, Phase 5 for invoices), so that the justification for `horizon`'s cap reflects what actually runs, not an assumption from Sprint 0.
- **ADR-006 does not match the installed code.** Its text says that Cashier 16 subscription invoices are generated with `spatie/laravel-pdf`, "not `dompdf`". `laravel/cashier` 16.8, however, defaults to `DompdfInvoiceRenderer` (`vendor/laravel/cashier/config/cashier.php`, `CASHIER_INVOICE_RENDERER`), and `LaravelPdfInvoiceRenderer` is only a commented-out option; Cashier's config is not published in the application. Until `dompdf/dompdf` was installed for this ADR, downloading a subscription invoice would have failed. Correcting ADR-006 (and choosing the invoice renderer) remains the owner's decision, at the latest in Phase 5.

## History

- 2026-09-14 — created. The owner chose Option 1 out of the six above, for the Orders list PDF export (Phase 3).
