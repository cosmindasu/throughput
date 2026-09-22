import type { HelpTopicDefinition } from '@/help/types';

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
const dealsKanban: HelpTopicDefinition = {
    id: 'deals-kanban',
};

export default dealsKanban;
