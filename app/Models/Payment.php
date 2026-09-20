<?php

namespace App\Models;

use App\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['invoice_id', 'amount', 'method', 'paid_at', 'created_by'])]
class Payment extends Model
{
    use BelongsToTenant, HasUlids;

    // §12.1 — „Fără card/Stripe în MVP" (ADR-005): o factură către clienți se
    // încasează manual, reconciliat de un Owner/Manager.
    public const METHOD_BANK_TRANSFER = 'bank_transfer';

    public const METHOD_CHECK = 'check';

    public const METHOD_MANUAL = 'manual';

    /** @return list<string> */
    public static function methods(): array
    {
        return [self::METHOD_BANK_TRANSFER, self::METHOD_CHECK, self::METHOD_MANUAL];
    }

    // Tabela nu are `created_at`/`updated_at` — doar `paid_at`.
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_at' => 'datetime',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
