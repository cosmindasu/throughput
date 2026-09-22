import type { HelpTopicDefinition } from '@/help/types';

/**
 * `Bulk/Groups/Show` — specs.md §13.2 (BR-BULK-04). Mirror-ul de grup al „Bulk operation
 * status": mai multe rânduri `bulk_operations` (un tip per rând), legate prin `group_id`.
 * Singurul caz din MVP: reatribuirea la dezactivarea unui membru (US-TEN-03) sau din
 * vederea „Unassigned".
 */
const bulkGroupOperationTopic: HelpTopicDefinition = {
    id: 'bulk-group-operation',
};

export default bulkGroupOperationTopic;
