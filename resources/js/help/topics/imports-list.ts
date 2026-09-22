import type { HelpTopicDefinition } from '@/help/types';

/**
 * `Imports/Index` — specs.md §14 (fluxul în 4 pași), §14.2 (tabela `imports`), §7.4 rândul
 * „Import CSV" (Owner/Manager CRUD, Agent și Viewer „—"), §22.5 (1 import activ per tenant).
 *
 * Reconciliat cu codul la 2026-09-19 (lotul N, Faza 4): `Pages/Imports/Index.tsx`
 * (etichetele de status, coloanele, „New import"/„Start your first import"),
 * `ImportController::index()` (`RECENT_IMPORTS_LIMIT` = 50, fără paginare pe cursor —
 * motivat acolo), `ImportPolicy` (fără îngustare pe proprietate: un Owner/Manager vede TOATE
 * importurile tenantului, nu doar ale lui), `ImportConcurrencyGuard` („activ" = orice status
 * NEterminal, inclusiv un import abandonat la „uploaded"), `Permissions::forRoles()` (nici
 * Agent, nici Viewer n-au `imports.view`, deci elementul lipsește și din `NAV_ITEMS`).
 */
const importsList: HelpTopicDefinition = {
    id: 'imports-list',
};

export default importsList;
