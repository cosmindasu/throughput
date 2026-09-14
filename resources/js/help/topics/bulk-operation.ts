import type { HelpTopic } from '@/help/types';

/**
 * `Bulk/Show` — specs.md §13.1/§13.2 (mecanismul generic de operații în masă), §13.4
 * (BR-BULK-02/03), FR-BULK-01. Pachetul C, valul „bulk" (plan-implementare.md §9). Aplicat
 * azi doar pe reasignarea de owner (Accounts, Deals) — mecanismul e generic, alte acțiuni
 * (anulare comenzi, preț în masă) se adaugă în valurile care le construiesc, fără o pagină
 * de status nouă.
 *
 * Reconciliat cu codul la 2026-09-14: `Pages/Bulk/Show.tsx` (etichetele de status, polling
 * la 2 s, bara de progres, „Cancel"), `DispatchBulkOperationAction` (pragul de rol,
 * plafonul absolut DEMO_MODE), `PlanBulkOperationJob`/`ProcessBulkChunkJob`
 * (planificator + chunk-uri, `Bus::batch`), `BulkOperationPolicy` (doar autorul),
 * `BulkConfirmationThreshold` (FR-BULK-01).
 */
const bulkOperationTopic: HelpTopic = {
    id: 'bulk-operation',
    title: 'Bulk operation status',
    whatIsThis:
        'This page follows a change you made to many rows at once — like reassigning the owner of hundreds of accounts or deals in one action. It runs in the background so your browser never hangs; you land here automatically after confirming.',
    whatCanYouDo: [
        'Watch the status go from "Queued" to "Running" to "Done" — the page updates itself every 2 seconds until it finishes.',
        'Read the progress line for how many rows are done so far, out of the total.',
        'Press "Cancel" while it\'s running — rows already changed keep their new owner, the rest are left exactly as they were.',
        'Come back later through the same link, in the public demo until the nightly data reset.',
    ],
    rules: [
        'Before you get here, a confirmation dialog appears whenever the change would touch more than a role-based number of rows: 125 for Agent, 1,000 for Owner and Manager — a smaller number for Agent because their whole role is capped at 500 rows per operation, so the check has to matter for them too.',
        'Selecting the checkbox in the table header picks only the rows on the current page. A separate link, "Select all N matching this filter", appears next to it and picks every row that matches your filters, across all pages — one is never assumed from the other.',
        "If a handful of rows fail (say, one was deleted by someone else while the operation was running), the rest still complete — you'll see how many failed here, not a single all-or-nothing error.",
        'As an Agent, a bulk change only ever touches accounts or deals you own or created, even if your current filter shows more — the same rule as editing one at a time, just enforced again on the whole batch.',
        'Only the person who started an operation can open this page or cancel it — not even an Owner can cancel a teammate\'s.',
        'In the public demo, an operation touching more than 60,000 rows is refused before it starts.',
    ],
    howItsBuilt: {
        summary:
            'The request never resolves a list of IDs itself — it saves the filter (or, for a page-only selection, the exact IDs checked) on a `bulk_operations` row and queues a planner job. That job re-runs the same query used by the list screen, in ID-ordered pages of 500 rows at a time so a 60,000-row operation never loads more than one page into memory, and groups one job per page into a `Bus::batch()` that allows individual failures without stopping the rest. Each of those jobs writes a single conditional `UPDATE … WHERE owner_user_id != :new_owner`, so re-running the same page after a retry changes nothing the second time. Cancelling calls `$batch->cancel()`; each job checks that flag for itself before writing anything, since Laravel doesn\'t kill jobs already queued. No dedicated ADR — see specs.md §13.1/§13.2 for the full mechanism.',
    },
};

export default bulkOperationTopic;
