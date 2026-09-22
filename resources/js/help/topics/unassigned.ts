import { adrUrl } from '@/help/adr';
import type { HelpTopicDefinition } from '@/help/types';

/**
 * `Unassigned/Index` — specs.md §6.4.1 (BR-TEN-05, FR-TEN-05), ADR-011.
 * `UnassignedController`, `DealList`/`OrderList` (filtrul `owner=unassigned`).
 */
const unassignedTopic: HelpTopicDefinition = {
    id: 'unassigned',
    adr: {
        id: 'ADR-011',
        title: 'Deactivating a member is not blocked by the records they own',
        url: adrUrl('ADR-011', 'dezactivare-membru-fara-blocare'),
    },
};

export default unassignedTopic;
