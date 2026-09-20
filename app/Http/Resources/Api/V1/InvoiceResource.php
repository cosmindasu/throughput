<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Contractul public al unei facturi (specs.md §18, §12.1).
 *
 * `pdfStatus` e expus, `pdfPath` NU: PDF-ul se generează într-un job (ADR-013), iar calea
 * de pe disc e un detaliu de infrastructură, nu parte din contract. Un consumator care
 * vrea fișierul urmărește `pdfStatus` până la `ready` — exact ce face și interfața.
 *
 * @mixin Invoice
 */
final class InvoiceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'invoiceNumber' => $this->invoice_number,
            'orderId' => $this->order_id,
            'status' => $this->status,
            'issueDate' => $this->issue_date?->toDateString(),
            'dueDate' => $this->due_date?->toDateString(),
            'currency' => $this->currency,
            'subtotal' => (float) $this->subtotal,
            'taxTotal' => (float) $this->tax_total,
            'total' => (float) $this->total,
            'amountPaid' => (float) $this->amount_paid,
            'balanceDue' => (float) $this->balance_due,
            'pdfStatus' => $this->pdf_status,
            'voidReason' => $this->void_reason,
            'voidedAt' => $this->voided_at?->toIso8601String(),
            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}
