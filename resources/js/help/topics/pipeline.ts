import type { HelpTopicDefinition } from '@/help/types';

/**
 * `Pipeline/Index` — specs.md §9.2/9.5 (FR-DEAL-02, BR-DEAL-01). Nu e în
 * `NAV_ITEMS` (se ajunge din „Manage pipeline" pe board sau din cardul „Pipeline" din
 * Settings) — inclus în harta de subiecte oricum, per BR-HELP-04.
 *
 * Reconciliat cu codul la 2026-09-13: `Pages/Pipeline/Index.tsx` („Add stage", „Edit"/„Save",
 * „Delete", săgețile ↑/↓ și drag pe rând), `PipelinePolicy` (Viewer are `pipelines.view`, Agent
 * nu are nimic), `SaveStageAction` (nume unic, cel mult un Won și un Lost),
 * `Stage::deletionBlockedReason()` (ORICE etapă cu deals, nu doar Won/Lost, și orice etapă cu istoric),
 * `ReorderStagesAction` și FK-ul restrictiv `deal_stage_events.to_stage_id`.
 */
const pipeline: HelpTopicDefinition = {
    id: 'pipeline',
};

export default pipeline;
