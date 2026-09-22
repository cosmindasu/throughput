import { adrUrl } from '@/help/adr';
import type { HelpTopicDefinition } from '@/help/types';

/**
 * `Dashboard` — specs.md §21.3 (FR-DEMO-01), app/Http/Controllers/Web/DashboardController.php.
 * Nu e în `NAV_ITEMS` (nu e un modul cu link în bara principală), dar e prima pagină
 * după login — inclusă manual în testul de acoperire (FR-HELP-04).
 *
 * Reconciliat cu codul la 2026-09-13: etichetele din `Pages/Dashboard.tsx`, agregatele din
 * `DashboardController::show()` (`inventory_levels` e per variantă × locație), feed-ul pe rol
 * din `DashboardController::recentActivity()` (§7.4), comutatorul
 * din `WorkspaceSwitcher.tsx` și scurtăturile reale din `GlobalSearch.tsx` (Cmd/Ctrl+K,
 * ambele modificatoare pe orice platformă) și `HelpPanel.tsx` (`?` în afara câmpurilor de
 * text, `Esc`).
 */
const dashboard: HelpTopicDefinition = {
    id: 'dashboard',
    adr: {
        id: 'ADR-003',
        title: 'Tenant isolation in two layers — Eloquent global scope + Row-Level Security',
        url: adrUrl('ADR-003', 'izolare-tenant-doua-straturi'),
    },
};

export default dashboard;
