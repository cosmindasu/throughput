<?php

namespace App\Http\Resources;

use App\Models\Invoice;
use App\Models\Payment;
use App\Support\Members\DeactivatedMemberNames;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * Forma unei facturi — folosită IDENTIC pe `Invoices/Index` (un rând) și `Invoices/Show`
 * (detaliul complet): spre deosebire de `Order` (care are `OrderResource`/
 * `OrderSummaryResource` separate, pentru liniile/shipment-urile grele de pe detaliu),
 * o factură n-are propriile linii — le împrumută de la comanda sursă (§12.1) — deci nu
 * există o formă „redusă" cu adevărat diferită de a justifica un al doilea fișier.
 *
 * `can` per RÂND, la fel ca `OrderSummaryResource`: pe listă, fiecare rând știe dacă
 * poate fi trimis/anulat/reîncercat, indiferent de rol (§7.5 — Agent vede TOATE facturile
 * comenzilor proprii, dar nu poate acționa pe niciuna, `invoices.edit`/`invoices.void`
 * lipsindu-i din catalog).
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
            'status' => $this->status,
            'statusLabel' => ucfirst((string) $this->status),
            'currency' => $this->currency,
            'subtotal' => (float) $this->subtotal,
            'taxTotal' => (float) $this->tax_total,
            'total' => (float) $this->total,
            'amountPaid' => (float) $this->amount_paid,
            'balanceDue' => (float) $this->balance_due,
            'issueDate' => $this->issue_date?->toDateString(),
            'dueDate' => $this->due_date?->toDateString(),
            'pdfStatus' => $this->pdf_status,
            'voidReason' => $this->void_reason,
            'voidedAt' => $this->voided_at?->toIso8601String(),
            'createdAt' => $this->created_at?->toIso8601String(),
            'order' => $this->whenLoaded('order', fn () => $this->order === null ? null : [
                'id' => $this->order->id,
                'orderNumber' => $this->order->order_number,
                'account' => $this->order->relationLoaded('account') && $this->order->account ? [
                    'id' => $this->order->account->id,
                    'name' => $this->order->account->name,
                ] : null,
                // FR-TEN-04 — placeholder „(deactivated)" pe owner (§6.4.1), ca `OrderResource`.
                'owner' => $this->order->relationLoaded('owner') && $this->order->owner ? [
                    'id' => $this->order->owner->id,
                    'name' => DeactivatedMemberNames::label($this->order->owner->name, $this->order->owner->id),
                ] : null,
            ]),
            'payments' => PaymentResource::collection($this->whenLoaded('payments')),
            'can' => [
                'view' => Gate::allows('view', $this->resource),
                'markSent' => Gate::allows('update', $this->resource),
                'void' => Gate::allows('void', $this->resource),
                'retryPdf' => Gate::allows('retryPdf', $this->resource),
                'registerPayment' => Gate::allows('create', [Payment::class, $this->resource]),
            ],
        ];
    }
}
