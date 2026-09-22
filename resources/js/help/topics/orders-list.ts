import type { HelpTopicDefinition } from '@/help/types';

/**
 * `Orders/Index` — specs.md §11.4/§11.6 (FR-ORD-02), §13.5 (lotul E, valul „bulk"). Tabel
 * filtrabil/sortabil pe cursor, la fel ca `Deals/Index`, plus operații în masă: reasignare
 * owner, anulare de draft-uri, export CSV/PDF.
 */
const ordersList: HelpTopicDefinition = {
    id: 'orders-list',
};

export default ordersList;
