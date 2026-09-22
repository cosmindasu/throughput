import { adrUrl } from '@/help/adr';
import type { HelpTopicDefinition } from '@/help/types';

/**
 * `Settings/DataExport/Index` — specs.md §20.5 (FR-GDPR-01/02, BR-GDPR-01/02, US-GDPR-01),
 * §7.4 (permisiunile `data_exports.view`/`data_exports.create`), plan §11 (lotul G, Faza 5).
 *
 * Distinct de `exports` (statusul unui export de LISTĂ, §13.2): acolo se exportă rândurile
 * unei liste filtrate, aici întreg workspace-ul, ca răspuns la o cerere de portabilitate.
 *
 * Scris pe codul acestui lot: `DataExportController` (istoric de 50 de rânduri, ordonat cu
 * tiebreaker pe `id`), `DataExportRequestPolicy` (doar autorul descarcă), `DataExportSources`
 * (cele șapte entități, CSV doar unde toate coloanele sunt scalare), `WriteEntityExportAction`
 * (scriere în flux, `chunkById` la 500), `PlanDataExportJob`/`ExportTenantEntityJob`/
 * `FinalizeDataExportJob` (`Bus::batch()`, FĂRĂ `allowFailures()`), `DataExportReadyMail`,
 * `PruneExpiredDataExportsJob` (`EXPORT_RETENTION_DAYS` = 7).
 */
const dataExport: HelpTopicDefinition = {
    id: 'data-export',
    adr: {
        id: 'ADR-013',
        title: 'External calls leave the HTTP request and move to queues',
        url: adrUrl('ADR-013', 'apeluri-externe-in-cozi'),
    },
};

export default dataExport;
