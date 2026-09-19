import type { HelpTopic } from '@/help/types';

/**
 * `Bulk/Groups/Show` — specs.md §13.2 (BR-BULK-04). Mirror-ul de grup al „Bulk operation
 * status": mai multe rânduri `bulk_operations` (un tip per rând), legate prin `group_id`.
 * Singurul caz din MVP: reatribuirea la dezactivarea unui membru (US-TEN-03) sau din
 * vederea „Unassigned".
 */
const bulkGroupOperationTopic: HelpTopic = {
    id: 'bulk-group-operation',
    title: 'Bulk operation status (reassigning multiple record types)',
    whatIsThis:
        'This page follows a reassignment that touches more than one type of record at once — for example, everything a deactivated member owned: their accounts, open deals and active orders together.',
    whatCanYouDo: [
        'Watch a separate progress bar for each record type — accounts, deals, orders — and an overall status above them.',
        'Press "Cancel" to stop every type at once — rows already reassigned keep their new owner, the rest are left unchanged.',
    ],
    rules: [
        'The overall status stays "Running" until every type is done — one still-queued type keeps the whole page showing progress, even if the others already finished.',
        'Only the person who started the operation can open this page or cancel it, the same rule as a single-type bulk operation.',
    ],
    howItsBuilt: {
        summary:
            'Each record type is its own row in `bulk_operations` and its own queued job pipeline — reassigning three types is three independent operations, not one job that touches three tables. They share nothing but a `group_id` column, so this page is a thin aggregation over three ordinary `BulkOperationResource` reads: it sums their totals for the header and reuses the same per-operation resource for each progress bar. If accounts fail to reassign, deals and orders keep going and can be retried independently.',
    },
};

export default bulkGroupOperationTopic;
