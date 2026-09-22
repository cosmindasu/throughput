import { adrUrl } from '@/help/adr';
import type { HelpTopicDefinition } from '@/help/types';

/**
 * `Reports/Show` — specs.md §16.2 (fluxul complet: scadență → `report_runs` → job →
 * fișier → email; pct. 5 — la eșec NU se trimite email), §16.3 (cele două rapoarte
 * built-in), US-REP-02 („Run now" + rezultat direct în interfață), FR-REP-01 (istoricul
 * rulărilor), ADR-009 (Resend ca transport).
 *
 * Reconciliat cu codul la 2026-09-19 (lotul N, Faza 4): `Pages/Reports/Show.tsx` („Run now",
 * „Edit", „Delete", secțiunile „Recipients"/„Current result"/„Run history", „Download" per
 * rând, dialogul „Delete {name}?" cu „Scheduled delivery will stop immediately", polling la
 * 3s cu ramura `start()` — tiparul corect din `.ai/rules/frontend.md`),
 * `ReportController::show()` (previzualizarea built-in e RECALCULATĂ la fiecare vizită, nu e
 * un instantaneu al ultimei rulări; `BUILT_IN_PREVIEW_ROW_CAP` = 200; `runs` = ultimele 20,
 * `id DESC`), `ReportDefinitionPolicy` („Run now" cere `reports.manage`; `download()` urmează
 * dreptul de citire, deci un Agent destinatar descarcă), `GenerateReportJob` (două tranzacții
 * scurte ca starea `running` să fie vizibilă la polling; plafoane `export_pdf_max_rows` =
 * 250 și `export_xlsx_max_rows` = 5.000; `DeliverReportJob` dispecerizat DOAR la succes),
 * `DeliverReportJob::failed()` (un eșec de EMAIL nu răstoarnă `report_runs.status`),
 * `DispatchScheduledReportsJob` (orar, ora capturată la tick, `ShouldBeUnique` + `FOR NO KEY
 * UPDATE`), `DealVelocityReport`/`InventoryValuationReport`.
 *
 * Cifrele 250/5.000 vin din `config/throughput.php` — plafonul PDF a FOST 500 și a coborât
 * la 250 în Faza 4, remăsurat pe container.
 */
const reportDetail: HelpTopicDefinition = {
    id: 'report-detail',
    adr: {
        id: 'ADR-009',
        title: 'Resend as the transactional email provider',
        url: adrUrl('ADR-009', 'resend-email-tranzactional'),
    },
};

export default reportDetail;
