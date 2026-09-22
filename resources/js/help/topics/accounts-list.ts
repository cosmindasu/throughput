import { adrUrl } from '@/help/adr';
import type { HelpTopicDefinition } from '@/help/types';

/**
 * `Accounts/Index` — specs.md §8 (FR-CRM-01/03, US-CRM-01…03), §15 (vizualizări
 * salvate), app/Support/Lists/AccountList.php + AccountPolicy.php.
 *
 * Reconciliat cu codul la 2026-09-13: butoanele și filtrele din `Pages/Accounts/Index.tsx`
 * („Export CSV", „New account", „Owner", „Status", „Sort by"), etichetele din
 * `SavedViewPicker.tsx` („Views", „Save view", „My views"/„Team views", „☆ Set default"),
 * `can` din `AccountController::index()`, implicitul „My accounts" din
 * `AccountList::defaultFilters()`, `SavedViewPolicy` și `SavedViewDefaultRedirect`.
 */
const accountsList: HelpTopicDefinition = {
    id: 'accounts-list',
    adr: {
        id: 'ADR-002',
        title: 'Path-based multi-tenancy (workspace slug), not subdomain-based',
        url: adrUrl('ADR-002', 'tenancy-pe-cale'),
    },
};

export default accountsList;
