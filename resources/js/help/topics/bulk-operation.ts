import type { HelpTopic } from '@/help/types';

/**
 * `Bulk/Show` — specs.md §13.1/§13.2 (mecanismul generic de operații în masă), §13.4
 * (BR-BULK-02/03), FR-BULK-01. Pachetul C, valul „bulk" (plan-implementare.md §9). Aplicat
 * pe reasignarea de owner (Accounts, Deals, Orders), anularea în masă a comenzilor draft și
 * preț/activare în masă pe Products (lotul E, §13.5) — un singur mecanism generic, fără o
 * pagină de status nouă per acțiune.
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
        'This page follows a change you made to many rows at once — like reassigning the owner of hundreds of accounts, cancelling a batch of draft orders, or changing the price of a batch of products in one action. It runs in the background so your browser never hangs; you land here automatically after confirming.',
    whatCanYouDo: [
        'Watch the status go from "Queued" to "Running" to "Done" — the page updates itself every 2 seconds until it finishes.',
        'Follow the progress line and bar — they show roughly how far along the operation is, worked out from how many batches of 500 rows have finished rather than from a per-row counter, so the figure is exact at the start and at the end and an estimate in between.',
        'Press "Cancel" while it\'s running — rows already changed keep their new value, the rest are left exactly as they were.',
        'Come back later through the same link, in the public demo until the nightly data reset.',
    ],
    rules: [
        'Before you get here, a confirmation dialog appears whenever the change would touch more than a role-based number of rows: 125 for Agent, 1,000 for Owner and Manager — a smaller number for Agent because their whole role is capped at 500 rows per operation, so the check has to matter for them too.',
        'Selecting the checkbox in the table header picks only the rows on the current page. A separate link, "Select all N matching this filter", appears next to it and picks every row that matches your filters, across all pages — one is never assumed from the other.',
        'A failure never stops the rest. The work is split into batches of 500 rows and one batch failing doesn\'t cancel the others — the page tells you how many batches failed, if any. Individual rows are not tracked one by one: a row that no longer matches when its batch runs (someone deleted it, or a draft order was confirmed in the meantime) is simply left untouched, and is not counted as a failure.',
        'As an Agent, a bulk change only ever touches records that are yours, even if your current filter shows more: accounts you own or created, and deals or orders you own. It\'s the same rule as editing them one at a time, enforced again on the whole batch — and at query level, so the count you confirmed already reflects it.',
        'Bulk cancelling draft orders only ever touches the drafts in your selection — the row count, the "Select all N" link and the confirmation threshold for that action all reflect the drafts only, never the raw filter or selection.',
        'Only the person who started an operation can open this page or cancel it — not even an Owner can cancel a teammate\'s.',
        'In the public demo, an operation touching more than 60,000 rows is refused before it starts.',
    ],
    howItsBuilt: {
        summary:
            'The request never resolves a list of IDs itself — it saves the filter (or, for a page-only selection, the exact IDs checked) on a `bulk_operations` row and queues a planner job. That job re-runs the same query used by the list screen, in ID-ordered pages of 500 rows at a time so a 60,000-row operation never loads more than one page into memory, and groups one job per page into a `Bus::batch()` that allows individual failures without stopping the rest. Each of those jobs writes a single conditional `UPDATE`, so re-running the same page after a retry changes nothing the second time — for reassignment that\'s `WHERE owner_user_id != :new_owner`; for cancelling drafts it\'s `WHERE status = \'draft\'`. A price change can\'t be written that way at all, since the new price is computed from the old one, so a redelivered chunk is stopped one level up instead: each chunk records itself in a `bulk_operation_chunks` row inside the same transaction as its work, and a chunk that finds its row already there does nothing. Cancelling calls `$batch->cancel()`; each job checks that flag for itself before writing anything, since Laravel doesn\'t kill jobs already queued. No dedicated ADR — see specs.md §13.1/§13.2 for the full mechanism.',
    },
};

export default bulkOperationTopic;
