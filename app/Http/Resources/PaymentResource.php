<?php

namespace App\Http\Resources;

use App\Models\Payment;
use App\Support\Members\DeactivatedMemberNames;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * O încasare înregistrată manual (US-BILL-02, §12.1) — randată în lista de plăți de pe
 * `Invoices/Show`.
 *
 * @mixin Payment
 */
final class PaymentResource extends JsonResource
{
    private const METHOD_LABELS = [
        Payment::METHOD_BANK_TRANSFER => 'Bank transfer',
        Payment::METHOD_CHECK => 'Check',
        Payment::METHOD_MANUAL => 'Manual',
    ];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'amount' => (float) $this->amount,
            'method' => $this->method,
            'methodLabel' => self::METHOD_LABELS[$this->method] ?? ucfirst((string) $this->method),
            'paidAt' => $this->paid_at?->toIso8601String(),
            // FR-TEN-04 — placeholder „(deactivated)" pe cine a înregistrat plata.
            'createdBy' => $this->whenLoaded('createdBy', fn () => $this->createdBy ? [
                'id' => $this->createdBy->id,
                'name' => DeactivatedMemberNames::label($this->createdBy->name, $this->createdBy->id),
            ] : null),
        ];
    }
}
