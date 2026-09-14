<?php

namespace App\Support\Bulk\Resources;

use App\Models\Deal;
use App\Models\User;
use App\Support\Bulk\BulkWritableResource;
use App\Support\Lists\DealList;
use Illuminate\Database\Eloquent\Builder;

/**
 * Deals — §13.5. Doar `owner_user_id` (ca `App\Policies\DealPolicy::isWithinOwnRecords()`):
 * spre deosebire de Accounts, un deal creat de un Agent îl face automat owner
 * (`CreateDealAction`), deci `created_by` n-ar acoperi niciun caz în plus.
 *
 * Notă RBAC (vezi raportul pachetului): `deals.change_owner` există azi doar la
 * Owner/Manager (`Permissions::forRoles()`) — un Agent nu poate reasigna owner-ul unui deal,
 * individual sau în masă. `scopeToOwnRecords()` există totuși aici pentru simetrie și pentru
 * orice acțiune VIITOARE pe Deals care ar fi permisă Agentului pe subsetul propriu.
 */
final class DealBulkResource implements BulkWritableResource
{
    public function resourceType(): string
    {
        return 'deals';
    }

    public function listClass(): string
    {
        return DealList::class;
    }

    public function modelClass(): string
    {
        return Deal::class;
    }

    public function newQuery(): Builder
    {
        return Deal::query();
    }

    public function scopeToOwnRecords(Builder $query, User $user): void
    {
        $query->where('owner_user_id', $user->getKey());
    }
}
