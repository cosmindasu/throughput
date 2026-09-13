import type { HelpTopic } from '@/help/types';

/**
 * `Pipeline/Index` — specs.md §9.2/9.5 (FR-DEAL-02, BR-DEAL-01). Nu e în
 * `NAV_ITEMS` (nu e o intrare a bării principale, se ajunge din Deals sau
 * Settings) — inclus în harta de subiecte oricum, per BR-HELP-04 (conținut scris
 * în faza care construiește ecranul).
 *
 * Presupuneri de buton semnalate în raport: „Add stage", „Save changes" —
 * confirmate la construirea paginii (pachet paralel).
 */
const pipeline: HelpTopic = {
    id: 'pipeline',
    title: 'Pipeline configuration',
    whatIsThis:
        "This is where the stages on the Deals board come from — their names, their order, and which ones count as won or lost. It's setup, not day-to-day selling.",
    whatCanYouDo: [
        'Add a new stage.',
        'Reorder stages by dragging them in the configuration list.',
        'Mark a stage as "Won" or "Lost" (the terminal stages the board treats specially).',
        'Rename a stage or delete one that\'s no longer used.',
    ],
    rules: [
        "Only Owner and Manager can open this screen — Agents and Viewers can see the board but not reconfigure it.",
        "A stage marked Won or Lost can't be deleted while any deal is currently sitting in it.",
        'This MVP supports one pipeline per workspace — multiple pipelines are a later addition, not built yet.',
    ],
    howItsBuilt: {
        summary:
            "The delete guard on Won/Lost stages exists for the same reason deal history is append-only (see the Deals — Kanban topic): every past stage transition points at a stage row by id, so removing a stage that deals still reference — or ever referenced — would leave the history ledger pointing at nothing. No dedicated ADR for this screen specifically; it follows directly from the append-only design argued in specs.md §9.1.",
    },
};

export default pipeline;
