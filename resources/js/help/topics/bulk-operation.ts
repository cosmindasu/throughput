import type { HelpTopicDefinition } from '@/help/types';

/**
 * `Bulk/Show` — specs.md §13.1/§13.2 (mecanismul generic de operații în masă), §13.4
 * (BR-BULK-02/03), FR-BULK-01. Pachetul C, valul „bulk" (plan-implementare.md §9). Aplicat
 * pe reasignarea de owner (Accounts, Deals, Orders), anularea în masă a comenzilor draft și
 * preț/activare în masă pe Products (lotul E, §13.5) — un singur mecanism generic, fără o
 * pagină de status nouă per acțiune.
 *
 * Reconciliat cu codul la 2026-09-14: `Pages/Bulk/Show.tsx` (etichetele de status, polling
 * la 2 s, bara de progres, „Cancel"), `DispatchBulkOperationAction` (pragul de rol,
 * plafonul absolut DEMO_MODE), `PlanBulkOperationJob`/`ProcessBulkChunkJob`
 * (planificator + chunk-uri, `Bus::batch`), `BulkOperationPolicy` (doar autorul),
 * `BulkConfirmationThreshold` (FR-BULK-01).
 */
const bulkOperationTopic: HelpTopicDefinition = {
    id: 'bulk-operation',
};

export default bulkOperationTopic;
