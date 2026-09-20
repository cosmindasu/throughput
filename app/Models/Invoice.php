<?php

namespace App\Models;

use App\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'order_id', 'invoice_number', 'status', 'issue_date', 'due_date', 'currency', 'subtotal', 'tax_total',
    'total', 'amount_paid', 'balance_due', 'pdf_path', 'pdf_status', 'void_reason', 'voided_at',
])]
class Invoice extends Model
{
    use BelongsToTenant, HasUlids;

    // §12.1 — „draft → sent → paid" (normal) sau „sent → overdue" (automat, BR-BILL-02)
    // sau „→ void" (din orice stare, cu motiv, BR-BILL-01). Un singur loc, ca la
    // `App\Enums\OrderStatus`, dar plain-string ca `Shipment` (nu enum-cast): tiparul
    // deja ales de scaffold-ul Fazei 1 pentru acest model — nu-l schimb aici (Faza 5
    // imită, nu redecide, convenția fișierului pe care-l moștenește).
    public const STATUS_DRAFT = 'draft';

    public const STATUS_SENT = 'sent';

    public const STATUS_PAID = 'paid';

    public const STATUS_OVERDUE = 'overdue';

    public const STATUS_VOID = 'void';

    // ADR-013: PDF-ul se generează într-un job — vezi comentariul din migrație.
    public const PDF_STATUS_PENDING = 'pending';

    public const PDF_STATUS_READY = 'ready';

    public const PDF_STATUS_FAILED = 'failed';

    protected function casts(): array
    {
        return [
            'issue_date' => 'date',
            'due_date' => 'date',
            'subtotal' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'total' => 'decimal:2',
            'amount_paid' => 'decimal:2',
            'balance_due' => 'decimal:2',
            'voided_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function isVoid(): bool
    {
        return $this->status === self::STATUS_VOID;
    }
}
