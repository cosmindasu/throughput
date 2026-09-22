import { adrUrl } from '@/help/adr';
import type { HelpTopicDefinition } from '@/help/types';

/**
 * `Stock/Show` — specs.md §10.2…§10.5 (BR-STOCK-01…04, US-STOCK-01…03),
 * app/Actions/Stock/RecordStockMovementAction.php, TransferStockAction.php.
 */
const stock: HelpTopicDefinition = {
    id: 'stock',
    adr: {
        id: 'ADR-004',
        title: 'Stock as an append-only ledger, not as a mutable quantity',
        url: adrUrl('ADR-004', 'stoc-registru-append-only'),
    },
};

export default stock;
