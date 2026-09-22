import { adrUrl } from '@/help/adr';
import type { HelpTopicDefinition } from '@/help/types';

/**
 * `Settings/Billing/Index` — specs.md §12.2 (abonamentul Throughput, Billable = tenant,
 * ADR-006), §12.3 (webhook-uri idempotente), §7.4 (Owner-only — Managerul nu vede nici
 * măcar cardul din Settings).
 *
 * Reconciliat cu codul la 2026-09-20 (lotul de abonament, Faza 5):
 * `App\Http\Controllers\Web\Settings\BillingController`, `SubscriptionBanner.tsx`,
 * `App\Http\Middleware\EnsureSubscriptionAccess`, `App\Support\Billing\SubscriptionAccessPolicy`.
 */
const billing: HelpTopicDefinition = {
    id: 'billing',
    adr: {
        id: 'ADR-006',
        title: 'Laravel Cashier 16 for the tenant subscription',
        url: adrUrl('ADR-006', 'cashier-16-pentru-abonament'),
    },
};

export default billing;
