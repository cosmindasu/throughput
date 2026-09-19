<?php

namespace App\Support\Bulk\Resources;

use App\Models\Order;
use App\Models\User;
use App\Support\Bulk\BulkWritableResource;
use App\Support\Lists\OrderList;
use Illuminate\Database\Eloquent\Builder;

/**
 * Comenzi — §13.5 (reasignare owner, anulare de draft-uri, export). Doar `owner_user_id`, ca
 * `App\Policies\OrderPolicy::isWithinOwnRecords()`: un Agent care creează o comandă devine
 * automat owner-ul ei (`CreateOrderAction`), deci `created_by` n-ar acoperi niciun caz în plus.
 *
 * Folosită de două fluxuri: operațiile în masă de pe `Orders/Index` și reatribuirea la
 * dezactivarea unui membru (US-TEN-03, BR-BULK-04), unde comenzile sunt al treilea tip din
 * același `group_id`, lângă conturi și deals.
 */
final class OrderBulkResource implements BulkWritableResource
{
    public function resourceType(): string
    {
        return 'orders';
    }

    public function listClass(): string
    {
        return OrderList::class;
    }

    public function modelClass(): string
    {
        return Order::class;
    }

    public function newQuery(): Builder
    {
        return Order::query();
    }

    public function scopeToOwnRecords(Builder $query, User $user): void
    {
        $query->where('owner_user_id', $user->getKey());
    }
}
