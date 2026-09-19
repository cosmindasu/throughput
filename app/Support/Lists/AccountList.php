<?php

namespace App\Support\Lists;

use App\Models\Account;
use App\Models\Membership;
use App\Models\User;
use App\Support\Exports\ExportableList;
use App\Support\ListQuery;
use App\Support\Permissions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Lista de conturi — FR-CRM-03, US-CRM-02.
 */
final class AccountList extends ResourceList implements ExportableList
{
    public const STATUSES = [Account::STATUS_PROSPECT, Account::STATUS_ACTIVE, Account::STATUS_INACTIVE];

    /**
     * Pseudo-status pentru „conturile nearhivate ale membrului" (plan §9, reatribuirea la
     * dezactivare, US-TEN-03) — `prospect` SAU `active`, niciodată `inactive`. Nu e un
     * `Account::STATUS_*` real: un singur rând `bulk_operations` (BR-BULK-04) nu poate
     * exprima „status IN (...)" cu filtrul `status` existent, care acceptă o singură
     * valoare exactă.
     *
     * Construit pentru `MembersController::dispatchReassignment()`, dar `accepts()` de
     * mai jos nu-l poate distinge de un filtru venit din URL — orice cerere poate trimite
     * `?filter[status]=not_archived`. Acceptabil, deliberat: valoarea doar AGREGĂ două
     * statusuri deja vizibile separat (`prospect`, `active`) pe același ecran, cu aceeași
     * izolare de tenant/RLS ca restul filtrelor — nu expune nimic ce n-ar fi vizibil deja
     * prin două cereri separate.
     */
    public const STATUS_NOT_ARCHIVED = 'not_archived';

    protected function filterKeys(): array
    {
        return ['q', 'status', 'owner'];
    }

    protected function sortableColumns(): array
    {
        return ['name', 'created_at'];
    }

    protected function defaultSort(): string
    {
        return 'name';
    }

    /**
     * US-CRM-02: Agentul pornește pe „My accounts". Doar ca implicit — `filter[owner]=all`
     * îl scoate, iar un link trimis de un Manager se deschide cu filtrul din link.
     */
    protected function defaultFilters(User $user): array
    {
        return Permissions::restrictedToOwnRecords($user) ? ['owner' => 'me'] : [];
    }

    /** P2-004 — vezi `ResourceList::pinRoleDependentFiltersForSharing()`. */
    protected function roleDependentFilterKeys(): array
    {
        return ['owner'];
    }

    protected function accepts(string $key, string $value): bool
    {
        return match ($key) {
            'status' => in_array($value, self::STATUSES, true) || $value === self::STATUS_NOT_ARCHIVED,
            'owner' => in_array($value, ['me', 'all', 'unassigned'], true) || Str::isUlid($value),
            default => true,
        };
    }

    protected function baseQuery(): Builder
    {
        return Account::query()->with('owner:id,name');
    }

    protected function applyFilters(Builder $query, ListQuery $list, User $user): void
    {
        if (($search = $list->filter('q')) !== null) {
            $query->where('name', 'ilike', '%'.addcslashes($search, '%_\\').'%');
        }

        if (($status = $list->filter('status')) !== null) {
            if ($status === self::STATUS_NOT_ARCHIVED) {
                $query->whereIn('status', [Account::STATUS_PROSPECT, Account::STATUS_ACTIVE]);
            } else {
                $query->where('status', $status);
            }
        }

        match ($owner = $list->filter('owner')) {
            null, 'all' => null,
            'me' => $query->where('owner_user_id', $user->getKey()),
            // Vederea „Unassigned" din ADR-011: fără responsabil SAU cu un responsabil care nu
            // mai e membru activ. Dezactivarea nu reatribuie nimic (BR-TEN-03), deci conturile
            // unui coleg plecat ajung aici, nu se pierd.
            'unassigned' => $query->where(fn (Builder $unassigned) => $unassigned
                ->whereNull('owner_user_id')
                ->orWhereNotIn('owner_user_id', Membership::query()->where('status', Membership::STATUS_ACTIVE)->select('user_id'))),
            default => $query->where('owner_user_id', Str::lower($owner)),
        };
    }

    /** @return list<string> */
    public function exportHeaders(): array
    {
        return ['Name', 'Domain', 'Industry', 'Status', 'Credit terms', 'Owner', 'Created at'];
    }

    /**
     * @param  Account  $row
     * @return list<string|int|float|null>
     */
    public function exportRow(Model $row): array
    {
        return [
            $row->name,
            $row->domain,
            $row->industry,
            $row->status,
            $row->credit_terms,
            $row->owner?->name,
            $row->created_at?->toIso8601String(),
        ];
    }
}
