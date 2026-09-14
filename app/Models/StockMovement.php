<?php

namespace App\Models;

use App\Concerns\AppendOnly;
use App\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['variant_id', 'location_id', 'delta', 'reason', 'ref_type', 'ref_id', 'note'])]
class StockMovement extends Model
{
    use AppendOnly, BelongsToTenant, HasUlids;

    // Registru append-only (ADR-004) — tabela are doar `created_at`, fără `updated_at`.
    public const UPDATED_AT = null;

    // Enumul din migrația `stock_movements` (specs.md §10.2) — sursă unică pentru
    // Actions, FormRequests și `StockMovementList`.
    public const REASON_RECEIPT = 'receipt';

    public const REASON_SALE = 'sale';

    public const REASON_ADJUSTMENT = 'adjustment';

    public const REASON_RETURN = 'return';

    public const REASON_TRANSFER = 'transfer';

    /** @var list<string> */
    public const REASONS = [
        self::REASON_RECEIPT,
        self::REASON_SALE,
        self::REASON_ADJUSTMENT,
        self::REASON_RETURN,
        self::REASON_TRANSFER,
    ];

    protected function casts(): array
    {
        return [
            'delta' => 'integer',
        ];
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(Variant::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
