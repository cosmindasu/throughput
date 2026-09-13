import { adrUrl } from '@/help/adr';
import type { HelpTopic } from '@/help/types';

/**
 * `Deals/Kanban` — specs.md §9.1…§9.4 (FR-DEAL-01/03, BR-DEAL-01/02, US-DEAL-01),
 * resources/js/Pages/Deals/Kanban.tsx (pachet paralel).
 *
 * Presupuneri de buton semnalate în raport: „New Deal", „Move to stage…", „Mark as
 * Lost" — confirmate la construirea paginii.
 */
const dealsKanban: HelpTopic = {
    id: 'deals-kanban',
    title: 'Deals — Kanban',
    whatIsThis:
        'Your sales pipeline as a board: one column per stage, one card per deal. Drag a card to move it forward — or use the keyboard, see below.',
    whatCanYouDo: [
        'Drag a card from one stage to another.',
        'Open "Move to stage…" on any card for a keyboard-friendly way to do the same thing.',
        'Open "New Deal" to add one directly to the first stage of the pipeline.',
        'Mark a deal "Lost" with a reason, or set its value and move it to "Won".',
        'Click a card to open the deal\'s full detail.',
    ],
    rules: [
        "A deal can't move to Won without a value — the move is rejected with an explicit message and the card stays where it was, whether you dragged it or used the menu.",
        "Dragging isn't the only way to change stage — every card has \"Move to stage…\", fully operable from the keyboard (Tab to the card, Enter to open the menu, arrow keys and Enter to pick a stage). This isn't a nice-to-have: drag-and-drop alone would fail WCAG 2.2 accessibility for anyone who can't use a mouse.",
        'Marking a deal "Lost" requires picking a reason from a fixed list (price, competitor, timing, other) — it can\'t be left blank.',
        'Viewers see the board read-only: no drag handle, no "Move to stage…" menu.',
        'Agents can only move deals they own.',
    ],
    howItsBuilt: {
        summary:
            "Moving a card never overwrites anything — it inserts a new row into `deal_stage_events` (from stage, to stage, who, when) and only then updates the deal's current-stage pointer, in one transaction. Nothing in that history table is ever edited or deleted after the fact. That's the only way to later answer questions like \"how long do deals sit in Qualified\" or \"which agent moves deals fastest\" — a single mutable `stage_id` column could never have answered them, it only knows where a deal is now, not the path it took to get there. The same argument, made originally for inventory (ADR-004), applies here by direct analogy (specs.md §9.1).",
        adr: {
            id: 'ADR-004',
            title: 'Inventory as an append-only ledger, not a mutable quantity — applied here to deal-stage history',
            url: adrUrl('ADR-004', 'stoc-registru-append-only'),
        },
    },
};

export default dealsKanban;
