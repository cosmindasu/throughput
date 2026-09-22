import { adrUrl } from '@/help/adr';
import type { HelpTopicDefinition } from '@/help/types';

/**
 * `Invoices/Show` — specs.md §12.1 (US-BILL-01/02, BR-BILL-01/02), plan §11 (Faza 5,
 * lotul A). Reconciliat cu codul la 2026-09-20: `InvoiceController`
 * (`CreateInvoiceAction`, `MarkInvoiceSentAction`, `VoidInvoiceAction`),
 * `PaymentController` (`RegisterPaymentAction`), `InvoicePolicy`/`PaymentPolicy`,
 * `GenerateInvoicePdfJob` (ADR-013), `MarkOverdueInvoicesJob` (BR-BILL-02).
 */
const invoiceDetail: HelpTopicDefinition = {
    id: 'invoice-detail',
    adr: {
        id: 'ADR-013',
        title: 'External calls leave the HTTP request and move to queues',
        url: adrUrl('ADR-013', 'apeluri-externe-in-cozi'),
    },
};

export default invoiceDetail;
