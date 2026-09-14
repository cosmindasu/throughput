<?php

namespace App\Support\Bulk\Resources;

use App\Models\Account;
use App\Models\User;
use App\Support\Bulk\BulkWritableResource;
use App\Support\Lists\AccountList;
use Illuminate\Database\Eloquent\Builder;

/**
 * Conturi — §13.5. Aceeași regulă de proprietate ca `App\Policies\AccountPolicy::isWithinOwnRecords()`
 * (owner SAU creator), dusă la nivel de interogare pentru chunk-uri, nu de instanță — un
 * Agent poate reasigna în masă doar conturile pe care le deține sau le-a creat.
 */
final class AccountBulkResource implements BulkWritableResource
{
    public function resourceType(): string
    {
        return 'accounts';
    }

    public function listClass(): string
    {
        return AccountList::class;
    }

    public function modelClass(): string
    {
        return Account::class;
    }

    public function newQuery(): Builder
    {
        return Account::query();
    }

    public function scopeToOwnRecords(Builder $query, User $user): void
    {
        $query->where(fn (Builder $owned) => $owned
            ->where('owner_user_id', $user->getKey())
            ->orWhere('created_by', $user->getKey()));
    }
}
