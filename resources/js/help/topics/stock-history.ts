import { adrUrl } from '@/help/adr';
import type { HelpTopicDefinition } from '@/help/types';

/**
 * `Stock/History` — FR-STOCK-03, app/Support/Lists/StockMovementList.php.
 */
const stockHistory: HelpTopicDefinition = {
    id: 'stock-history',
    adr: {
        id: 'ADR-004',
        title: 'Stock as an append-only ledger, not as a mutable quantity',
        url: adrUrl('ADR-004', 'stoc-registru-append-only'),
    },
};

export default stockHistory;
