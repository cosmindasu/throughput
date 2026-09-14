<?php

namespace App\Models;

use App\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['name', 'category', 'unit_of_measure', 'is_active'])]
class Product extends Model
{
    use BelongsToTenant, HasUlids;

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function variants(): HasMany
    {
        return $this->hasMany(Variant::class);
    }

    /**
     * Stare a produsului, nu un drept al utilizatorului (§7.5, la fel ca
     * `Account::deletionBlockedReason()`) — `products.delete` rămâne `true` în
     * `ProductPolicy`, ca butonul să existe și mesajul să explice refuzul (§7.3).
     *
     * `variants->product_id` are `cascadeOnDelete()` (migrația `products`), dar
     * `stock_movements.variant_id` și `order_lines.variant_id` NU au cascadă — un
     * `DELETE` necondiționat pe un produs cu variante ce au istoric ar cădea pe
     * constrângerea de FK în mijlocul cascadei, cu un 500 brut de la Postgres în loc
     * de un mesaj citibil.
     */
    public function deletionBlockedReason(): ?string
    {
        $withMovements = $this->variants()->whereHas('stockMovements')->count();
        $withOrders = $this->variants()->whereHas('orderLines')->count();

        if ($withMovements === 0 && $withOrders === 0) {
            return null;
        }

        $parts = [];

        if ($withMovements > 0) {
            $parts[] = $withMovements.' '.Str::plural('variant', $withMovements).' with stock history';
        }

        if ($withOrders > 0) {
            $parts[] = $withOrders.' '.Str::plural('variant', $withOrders).' used on orders';
        }

        return 'This product cannot be deleted: it has '.implode(' and ', $parts).'.';
    }
}
