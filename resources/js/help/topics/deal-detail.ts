import { adrUrl } from '@/help/adr';
import type { HelpTopic } from '@/help/types';

/**
 * `Deals/Show`, `Deals/Create`, `Deals/Edit` — specs.md §9.2/9.4/9.5 (FR-DEAL-03,
 * BR-DEAL-02, US-DEAL-01).
 *
 * Reconciliat cu codul la 2026-09-13: `Pages/Deals/Show.tsx` („Move to stage…", „Edit", „Delete",
 * secțiunea „Stage history"), `DealPolicy` (Agent doar pe deal-urile proprii, `changeOwner` doar
 * Owner/Manager), `StoreDealRequest` (contactul principal trebuie să fie al contului ales),
 * `CreateDealAction` (prima etapă, owner = creatorul), `MoveDealStageAction`, `LostReasonDialog`.
 *
 * Câmpul „Account" cu căutare (`AccountCombobox`), în Create ȘI în Edit, e schimbarea făcută în
 * paralel de alt agent: precompletat din `?account=` când se pleacă din pagina contului, gol
 * fără el; la schimbarea contului se golește contactul principal care nu aparține noului cont;
 * etapa, owner-ul și istoricul de etape rămân neschimbate.
 */
const dealDetail: HelpTopic = {
    id: 'deal-detail',
    title: 'Deal detail',
    whatIsThis:
        "This is one deal's full record — its value, its account, its owner, its current stage, and the complete history of how it got there. Creating and editing a deal use the same fields.",
    whatCanYouDo: [
        'On "New deal", pick the "Account" by searching its name, fill in the title and, if you already know it, the value, then "Create deal".',
        'Change the title, value, expected close date, account and primary contact with "Edit" — plus the owner, for Owner and Manager — then "Save changes".',
        'Move the deal to a different stage from "Move to stage…", the same menu used on the board.',
        'Review "Stage history": every stage change, who made it, when, and how long the deal spent on the stage before.',
        'Remove the deal with "Delete" — it disappears from lists, the board, search and reports, but its stage history is kept, so stage-velocity numbers stay correct.',
    ],
    rules: [
        'Starting from "New deal" on an account page fills in "Account"; otherwise the field starts empty. The primary contact has to be one of that account\'s contacts, so switching to another account clears a contact that doesn\'t belong to it.',
        'Changing the account leaves the stage, the owner and the stage history exactly as they were — and the stage never changes from this form, only from "Move to stage…" or the board.',
        'A new deal starts on the first stage of the pipeline, owned by whoever creates it unless an Owner or Manager picks someone else.',
        "A deal can't move to Won without a value set — same rule as on the board, enforced on the server either way. Marking it Lost requires a reason from a fixed list: Price, Competition, Timing or Other.",
        "An Agent can edit, move or delete only deals they own, and only Owner and Manager can change a deal's owner.",
    ],
    howItsBuilt: {
        summary:
            "Each row in \"Stage history\" is a `deal_stage_events` entry, inserted once and never updated — including the time spent in the previous stage, which is calculated at the moment of the move and stored, not recomputed later. That's what makes stage-velocity reporting possible at all; see the Kanban topic for the full argument.",
        adr: {
            id: 'ADR-004',
            title: 'Inventory as an append-only ledger, not a mutable quantity — applied here to deal-stage history',
            url: adrUrl('ADR-004', 'stoc-registru-append-only'),
        },
    },
};

export default dealDetail;
