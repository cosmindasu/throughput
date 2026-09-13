import { adrUrl } from '@/help/adr';
import type { HelpTopic } from '@/help/types';

/**
 * `Deals/Kanban` — specs.md §9.1…§9.4 (FR-DEAL-01/03, BR-DEAL-01/02, US-DEAL-01),
 * resources/js/Pages/Deals/Kanban.tsx.
 *
 * Reconciliat cu codul la 2026-09-13: `Pages/Deals/Kanban.tsx` (FĂRĂ buton de creare — `can.create`
 * vine din controller dar nu e randat; „My deals"/„All deals", „List"/„Board", „Manage pipeline",
 * „View all N"), `DealCard.tsx`, tastatura reală din `MoveStageMenu.tsx` (cardul nu e focusabil
 * cu Tab, butonul lui da), motivele din `LostReasonDialog.tsx`, `MoveDealStageAction` și plafonul
 * de 50 de carduri per coloană din `DealController::board()`.
 */
const dealsKanban: HelpTopic = {
    id: 'deals-kanban',
    title: 'Deals — Kanban',
    whatIsThis:
        'Your sales pipeline as a board: one column per stage, one card per deal. Drag a card to move it — or use the keyboard, see below.',
    whatCanYouDo: [
        'Drag a card from one stage to another.',
        'Use "Move to stage…" on a card to do the same thing without a mouse.',
        'Switch between "My deals" and "All deals", or to "List" for the table.',
        'Open a deal from its card title, or follow "View all" under a column that holds more deals than it shows.',
        'Go to "Manage pipeline" to change the stages (Owner and Manager only).',
    ],
    rules: [
        "A deal can't move to Won without a value — the move is rejected with \"Set a deal value before marking as Won\" and the card goes back where it was, whether you dragged it or used the menu. Set the value from the deal's \"Edit\" first.",
        "Dragging isn't the only way to change stage — \"Move to stage…\" is fully operable from the keyboard: Tab to the card's \"Move to stage…\" button, Enter, Space or ↓ to open it, ↑/↓ to pick a stage, Enter or Space to move, Esc to close. This isn't a nice-to-have: drag-and-drop alone would fail WCAG 2.2 accessibility for anyone who can't use a mouse.",
        'Moving a deal to the Lost stage first opens "Mark deal as Lost": pick a reason — Price, Competition, Timing or Other — then "Mark as Lost". Moving it out of Lost again clears the reason.',
        'Viewers see the board read-only: no dragging, no "Move to stage…" menu. Agents start on "My deals" and can move only deals they own.',
        "Each column shows its 50 most recently created deals; the number in the column header is the full count. There's no create button on the board — a new deal always starts on the first stage.",
    ],
    howItsBuilt: {
        summary:
            "Moving a card never overwrites anything — it inserts a new row into `deal_stage_events` (from stage, to stage, who, when, and how long the deal sat on the previous stage) and only then updates the deal's current-stage pointer, in one transaction with the deal row locked. History rows are never edited or removed afterwards: deleting a deal only hides it (a soft delete), so its moves still count in stage reports. That's the only way to later answer questions like \"how long do deals sit in Qualified\" or \"which agent moves deals fastest\" — a single mutable `stage_id` column could never have answered them, it only knows where a deal is now, not the path it took to get there. The same argument, made originally for inventory (ADR-004), applies here by direct analogy (specs.md §9.1).",
        adr: {
            id: 'ADR-004',
            title: 'Inventory as an append-only ledger, not a mutable quantity — applied here to deal-stage history',
            url: adrUrl('ADR-004', 'stoc-registru-append-only'),
        },
    },
};

export default dealsKanban;
