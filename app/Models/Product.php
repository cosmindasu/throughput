<?php

namespace App\Models;

use App\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
     *
     * ADR-022, Lot I18N Val 2 — `trans_choice()` în loc de `Str::plural()`, aceeași
     * motivație ca `Account::deletionBlockedReason()`: `Str::plural()` nu urmărește
     * `App::getLocale()`, deci ar produce gramatică englezească pe o interfață franceză.
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
            $parts[] = trans_choice('flash.products.deletion_blocked_stock_history_clause', $withMovements, ['count' => $withMovements]);
        }

        if ($withOrders > 0) {
            $parts[] = trans_choice('flash.products.deletion_blocked_used_on_orders_clause', $withOrders, ['count' => $withOrders]);
        }

        $joinedParts = count($parts) === 2
            ? $parts[0].' '.__('flash.common.list_and').' '.$parts[1]
            : $parts[0];

        return __('flash.products.deletion_blocked', ['parts' => $joinedParts]);
    }
}
