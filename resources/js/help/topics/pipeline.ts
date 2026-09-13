import type { HelpTopic } from '@/help/types';

/**
 * `Pipeline/Index` — specs.md §9.2/9.5 (FR-DEAL-02, BR-DEAL-01). Nu e în
 * `NAV_ITEMS` (se ajunge din „Manage pipeline" pe board sau din cardul „Pipeline" din
 * Settings) — inclus în harta de subiecte oricum, per BR-HELP-04.
 *
 * Reconciliat cu codul la 2026-09-13: `Pages/Pipeline/Index.tsx` („Add stage", „Edit"/„Save",
 * „Delete", săgețile ↑/↓ și drag pe rând), `PipelinePolicy` (Viewer are `pipelines.view`, Agent
 * nu are nimic), `SaveStageAction` (nume unic, cel mult un Won și un Lost),
 * `Stage::deletionBlockedReason()` (ORICE etapă cu deals, nu doar Won/Lost),
 * `ReorderStagesAction` și FK-ul restrictiv `deal_stage_events.to_stage_id`.
 */
const pipeline: HelpTopic = {
    id: 'pipeline',
    title: 'Pipeline configuration',
    whatIsThis:
        "This is where the stages on the Deals board come from — their names, their order, their win probability, and which ones count as Won or Lost. It's setup, not day-to-day selling.",
    whatCanYouDo: [
        'Add a stage under "Add stage": a name, an optional "Probability %" from 0 to 100, and "Won" or "Lost" if it ends a deal.',
        'Reorder stages by dragging a row, or with its ↑ and ↓ buttons from the keyboard.',
        'Rename a stage, change its probability or mark it Won or Lost with "Edit", then "Save".',
        'Remove a stage that\'s no longer used with "Delete".',
    ],
    rules: [
        'Only Owner and Manager can change stages. Viewers can open this page read-only — no reordering, "Edit", "Delete" or "Add stage" — and Agents can\'t open it at all.',
        "A stage with deals on it can't be deleted, whether or not it's Won or Lost — the dialog says how many deals to move first. A stage that deals have passed through before is refused as well, because their stage history still points at it.",
        "A pipeline has at most one Won stage and one Lost stage, a stage can't be both, and stage names are unique within the pipeline.",
        'New stages are added at the end of the order.',
        'This MVP supports one pipeline per workspace — multiple pipelines are a later addition, not built yet.',
    ],
    howItsBuilt: {
        summary:
            "Deleting a stage has two guards. The screen counts the deals currently on the stage and explains the block before you confirm. Underneath, every past move in `deal_stage_events` points at its stage by id through a restrictive foreign key, so the database also refuses to remove a stage any deal has ever entered — the history ledger can't be left pointing at nothing, for the same reason deal history is append-only (see the Deals — Kanban topic). Reordering sends the complete new order, and the server rejects it unless it lists every stage exactly once. No dedicated ADR for this screen specifically; it follows directly from the append-only design argued in specs.md §9.1.",
    },
};

export default pipeline;
