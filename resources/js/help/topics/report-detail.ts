import { adrUrl } from '@/help/adr';
import type { HelpTopic } from '@/help/types';

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
const reportDetail: HelpTopic = {
    id: 'report-detail',
    title: 'Report detail',
    whatIsThis:
        'One report: its schedule and recipients, the live result if it is a built-in, a button to run it on the spot, and every run it has ever had, with the file each one produced.',
    whatCanYouDo: [
        'Press "Run now" to queue a run immediately instead of waiting for the schedule — the status region below tells you where it is, and refreshes itself every 3 seconds until the run finishes.',
        'Read "Current result" for a built-in report: the numbers as they are right now, recomputed on every visit, not a snapshot of the last file.',
        'Download any successful run from "Run history" with "Download" — the file is the one that was emailed, byte for byte.',
        'See why a run went wrong: a failed row carries the actual error message under its status, so you are not left guessing why no email arrived.',
        'Open "Edit" to change the schedule, format or recipients, or "Delete" to remove the report for good.',
    ],
    rules: [
        'A failed run never sends an email. The file is generated first, and only a successful generation queues the delivery — so an empty or broken file cannot go out to your recipients (specs.md §16.2 pt. 5).',
        "The reverse holds too: if the file was generated but the email itself failed to leave, the run stays \"Success\" and the file stays downloadable here. A delivery problem doesn't rewrite the history of the generation, and retrying the email doesn't regenerate the report.",
        '"Run now" is Owner and Manager only, even though an Agent who is a recipient can open this page. Running a report spends queue time and creates a new run — that is a write, and the permission matrix gives Agent read only. The same Agent can still download the files from the history.',
        'A PDF run is capped at 250 rows and an XLSX run at 5,000. Past that, the run fails with exactly that reason and a suggestion to use CSV — both formats build the whole document in memory before writing it, and the queue worker has a hard memory ceiling. CSV is streamed and has no such cap.',
        '"Current result" only appears for the two built-in reports; a saved-view report has no in-page preview, only files. "Deal Velocity by Stage" shows, per pipeline and stage, the average days deals spend in that stage, how many deals ever reached it, and the conversion rate into the next stage. "Inventory Valuation" shows units on hand and total value (on hand × cost) per location and category.',
        'A stage nothing ever leaves — typically "Won" and "Lost" — has no average time in stage. That number is measured when a deal moves out of a stage, and a terminal stage has no such move, so the cell is empty rather than zero.',
        "For \"Inventory Valuation\", the in-page result is hidden from anyone who can't see stock cost: an Agent recipient gets an explanation instead of the table, because cost and margin are Owner/Manager figures everywhere else in the app. The file itself is not filtered — there is one file per run, sent identically to every address — so who you put in the recipients list is the real control.",
        'Deleting is permanent and stops scheduled delivery immediately. The dialog says so before you confirm.',
    ],
    howItsBuilt: {
        summary:
            'A run moves through "Queued" → "Running" → "Success"/"Failed" in two deliberately separate short transactions: if the whole job ran inside one transaction, "Running" would never be committed on its own and this page would jump straight from queued to finished, with nothing to poll. Delivery is a second job, dispatched only on success, which is how "no email on failure" is guaranteed structurally rather than by remembering to check. Scheduling runs hourly and is protected against double-firing twice over: a queue-level uniqueness lock rejects a second dispatch inside the same hour, and the check for "has this report already run in this window?" holds a row lock on the report definition itself — a plain read-then-insert under two concurrent runs would let both read "no" and both insert. The hour compared against the schedule is the one captured when the scheduler ticked, not the one read when the job finally executes: with a single queue worker, a job that waits behind others would otherwise look at the wrong hour and silently skip a due report. The built-in results on this page are aggregates bounded by your configuration — stages per pipeline, locations per category — never by transaction volume, which is what makes them cheap enough to compute inside the page request at all.',
        adr: {
            id: 'ADR-009',
            title: 'Resend as the transactional email provider',
            url: adrUrl('ADR-009', 'resend-email-tranzactional'),
        },
    },
};

export default reportDetail;
