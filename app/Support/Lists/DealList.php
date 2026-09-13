<?php

namespace App\Support\Lists;

use App\Models\Deal;
use App\Models\Membership;
use App\Models\User;
use App\Support\ListQuery;
use App\Support\Permissions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Lista de deals — `Deals/Index`, task-ul Pachetului C punctul 4. Filtre `q`/`status`/
 * `stage`/`owner` (owner ca la `AccountList`); sortări `title`/`value`/
 * `expected_close_date`/`created_at`. Scriptul k6 lovește `/deals?sort=-value&status=open`.
 */
final class DealList extends ResourceList
{
    public const STATUSES = [Deal::STATUS_OPEN, Deal::STATUS_WON, Deal::STATUS_LOST];

    protected function filterKeys(): array
    {
        return ['q', 'status', 'stage', 'owner'];
    }

    protected function sortableColumns(): array
    {
        return ['title', 'value', 'expected_close_date', 'created_at'];
    }

    protected function defaultSort(): string
    {
        return '-created_at';
    }

    /**
     * Ca la `AccountList` (US-CRM-02): un Agent pornește pe „My deals".
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
            'status' => in_array($value, self::STATUSES, true),
            'stage' => Str::isUlid($value),
            'owner' => in_array($value, ['me', 'all', 'unassigned'], true) || Str::isUlid($value),
            default => true,
        };
    }

    protected function baseQuery(): Builder
    {
        return Deal::query()->with(['account:id,name', 'owner:id,name', 'stage:id,name,is_won,is_lost']);
    }

    protected function applyFilters(Builder $query, ListQuery $list, User $user): void
    {
        if (($search = $list->filter('q')) !== null) {
            $query->where('title', 'ilike', '%'.addcslashes($search, '%_\\').'%');
        }

        if (($status = $list->filter('status')) !== null) {
            $query->where('status', $status);
        }

        if (($stage = $list->filter('stage')) !== null) {
            $query->where('stage_id', $stage);
        }

        match ($owner = $list->filter('owner')) {
            null, 'all' => null,
            'me' => $query->where('owner_user_id', $user->getKey()),
            // Owner dezactivat, nereatribuit (ADR-011) — la fel ca „Unassigned" din
            // `AccountList`, deși `deals.owner_user_id` nu e nullabil: un deal nu poate
            // fi „fără" owner, dar poate fi orfan de un owner încă activ.
            'unassigned' => $query->where(fn (Builder $unassigned) => $unassigned
                ->whereNotIn('owner_user_id', Membership::query()->where('status', Membership::STATUS_ACTIVE)->select('user_id'))),
            default => $query->where('owner_user_id', Str::lower($owner)),
        };
    }

    /**
     * Coloanele sortabile nullabile și coloana generată, NOT NULL, pe care se sortează de fapt.
     * Numele din stânga rămân singurele nume publice din URL.
     */
    private const GENERATED_SORT_COLUMNS = [
        'value' => 'value_sort',
        'expected_close_date' => 'expected_close_date_sort',
    ];

    /**
     * Suprascrie orchestrarea din `ResourceList::query()` DOAR pentru sortare, ca să nu
     * ating `App\Support\ListQuery::applySort()` (folosit de `AccountList` și de orice
     * listă viitoare scrisă de alt agent în paralel — plan §1.2 regula 6).
     *
     * Capcana (e) din task: `deals.value` și `deals.expected_close_date` sunt nullabile, iar
     * `cursorPaginate()` compară strict pe coloana de sortare. O comparație SQL cu NULL nu e
     * niciodată adevărată, deci un deal fără valoare dispărea din TOATE paginile pe
     * `sort=-value`, iar pe `sort=expected_close_date` a doua pagină dădea 500. Coloanele din
     * `GENERATED_SORT_COLUMNS` (migrațiile dedicate) sunt NOT NULL prin construcție.
     */
    public function query(ListQuery $list, User $user): Builder
    {
        $query = $this->baseQuery();

        $this->applyFilters($query, $list, $user);

        $direction = $list->sortDirection();
        $column = self::GENERATED_SORT_COLUMNS[$list->sortColumn()] ?? $list->sortColumn();

        return $query
            ->orderBy($column, $direction)
            ->orderBy($query->getModel()->getKeyName(), $direction);
    }
}
