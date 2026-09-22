import type { HelpTopicDefinition } from '@/help/types';

/**
 * `Invoices/Index` — specs.md §12.1 (FR-BILL, US-BILL-01/02), plan §11 (Faza 5, lotul A).
 * Listă simplă, pe cursor, la fel ca Orders — fără selector de coloane, vizualizări
 * salvate sau operații în masă (niciuna cerută pentru acest modul în MVP).
 */
const invoicesList: HelpTopicDefinition = {
    id: 'invoices-list',
};

export default invoicesList;
