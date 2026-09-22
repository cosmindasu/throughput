import { adrUrl } from '@/help/adr';
import type { HelpTopicDefinition } from '@/help/types';

/**
 * `Activity/Index` — specs.md §17 (FR-AUD-01…04, US-AUD-01, BR-AUD-01), Faza 5, lotul E.
 *
 * Distinct de tab-ul „History" montat pe fiecare pagină de detaliu (Accounts, Contacts,
 * Deals, Products, Orders): acesta e ecranul TENANT-WIDE, cu filtre, nu o cronologie a unei
 * singure entități — de aici și restricția de rol de mai jos, care NU se aplică tab-urilor
 * de entitate (oricine poate vedea o variantă îi poate vedea și istoricul de preț).
 */
const activityLog: HelpTopicDefinition = {
    id: 'activity-log',
    adr: {
        id: 'ADR-007',
        title: 'Activity log written in-house, not owen-it/laravel-auditing',
        url: adrUrl('ADR-007', 'audit-log-cod-propriu'),
    },
};

export default activityLog;
