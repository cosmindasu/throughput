<?php

namespace App\Models;

use App\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['product_id', 'sku', 'attributes', 'price', 'cost', 'weight', 'is_active', 'low_stock_threshold'])]
class Variant extends Model
{
    use BelongsToTenant, HasUlids;

    protected function casts(): array
    {
        return [
            'attributes' => 'array',
            'price' => 'decimal:2',
            'cost' => 'decimal:2',
            'weight' => 'decimal:3',
            'is_active' => 'boolean',
            'low_stock_threshold' => 'integer',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function inventoryLevels(): HasMany
    {
        return $this->hasMany(InventoryLevel::class);
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function orderLines(): HasMany
    {
        return $this->hasMany(OrderLine::class);
    }

    /**
     * La fel ca `Product::deletionBlockedReason()` — stare, nu drept. `inventory_levels`
     * cascadează la ștergerea variantei (migrația `inventory_levels`), dar
     * `stock_movements` (append-only, ADR-004) și `order_lines` NU, deliberat: un ledger
     * care s-ar șterge odată cu varianta n-ar mai fi un registru.
     */
    public function deletionBlockedReason(): ?string
    {
        if ($this->stockMovements()->exists()) {
            return 'This variant cannot be deleted: it has recorded stock movements.';
        }

        if ($this->orderLines()->exists()) {
            return 'This variant cannot be deleted: it is used on at least one order.';
        }

        return null;
    }
}
