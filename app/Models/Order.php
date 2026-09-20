<?php

namespace App\Models;

use App\Concerns\BelongsToTenant;
use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'order_number', 'account_id', 'contact_id', 'deal_id', 'owner_user_id', 'status',
    'currency', 'subtotal', 'discount_total', 'shipping_total', 'grand_total', 'placed_at', 'notes',
])]
class Order extends Model
{
    use BelongsToTenant, HasUlids;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_PARTIALLY_FULFILLED = 'partially_fulfilled';

    public const STATUS_FULFILLED = 'fulfilled';

    public const STATUS_CANCELLED = 'cancelled';

    protected function casts(): array
    {
        return [
            // §9 task ("mașina de stări") — un singur loc știe tranzițiile legale
            // (`App\Enums\OrderStatus::canTransitionTo()`); acest cast e ce face
            // `$order->status` întoarce enumul, nu un string brut, peste tot în cod.
            'status' => OrderStatus::class,
            'subtotal' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'shipping_total' => 'decimal:2',
            'grand_total' => 'decimal:2',
            'placed_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function orderLines(): HasMany
    {
        return $this->hasMany(OrderLine::class);
    }

    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class);
    }

    /**
     * Review Faza 5 (lotul A, specs.md §12.1, BR-BILL-01) — `latestOfMany()`, NU un
     * `hasOne()` simplu: după un „Void" urmat de o factură de înlocuire, o comandă poate
     * avea DOUĂ rânduri `invoices` (cel vechi, `void`, păstrat pentru trasabilitate, și
     * cel activ). Fără ordonare explicită, `hasOne()` întoarce „primul rând găsit de
     * Postgres" — nedeterminat, reprodus: întorcea factura `void`, nu cea activă.
     *
     * Tiebreaker EXPLICIT pe `id`, nu doar `created_at`: coloana are precizie 0
     * (`information_schema.columns.datetime_precision`), deci o factură anulată și
     * înlocuirea ei — de obicei la câteva milisecunde distanță, în același test sau
     * aceeași cerere — pot avea `created_at` IDENTIC, caz în care Postgres n-are nicio
     * garanție de ordine între rândurile cu același maxim. `id` (ULID) e sortabil
     * lexicografic pe momentul creării la precizie de milisecundă — corect ca tiebreaker,
     * spre deosebire de `created_at` singur.
     */
    public function invoice(): HasOne
    {
        return $this->hasOne(Invoice::class)->latestOfMany(['created_at', 'id']);
    }
}
