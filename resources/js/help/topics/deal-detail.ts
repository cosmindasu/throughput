import { adrUrl } from '@/help/adr';
import type { HelpTopic } from '@/help/types';

/**
 * `Deals/Show`, `Deals/Create`, `Deals/Edit` — specs.md §9.2/9.4/9.5 (FR-DEAL-03,
 * BR-DEAL-02, US-DEAL-01), §17.3 (tab „History").
 *
 * Presupuneri de buton semnalate în raport: „Save", „Move to stage…", „Mark as Lost" —
 * confirmate la construirea paginilor (pachet paralel).
 */
const dealDetail: HelpTopic = {
    id: 'deal-detail',
    title: 'Deal detail',
    whatIsThis:
        "This is one deal's full record — its value, its account, its current stage, and the complete history of how it got there.",
    whatCanYouDo: [
        'Edit the title, value, expected close date and primary contact.',
        'Move the deal to a different stage from "Move to stage…", the same menu used on the board.',
        'Mark the deal "Lost" with a reason, or set a value and mark it "Won".',
        'Review the "History" tab: every stage change, who made it and when.',
    ],
    rules: [
        "A deal can't move to Won without a value set — same rule as on the board, enforced on the server either way.",
        'Marking "Lost" requires a reason from a fixed list (price, competitor, timing, other).',
        'The "History" tab never changes after the fact: each stage transition is a permanent row, not a value that gets corrected retroactively.',
        'An Agent can only edit deals they own.',
    ],
    howItsBuilt: {
        summary:
            "Each row in \"History\" is a `deal_stage_events` entry, inserted once and never updated — including the time spent in the previous stage, which is calculated at the moment of the move and stored, not recomputed later. That's what makes stage-velocity reporting possible at all; see the Kanban topic for the full argument.",
        adr: {
            id: 'ADR-004',
            title: 'Inventory as an append-only ledger, not a mutable quantity — applied here to deal-stage history',
            url: adrUrl('ADR-004', 'stoc-registru-append-only'),
        },
    },
};

export default dealDetail;
