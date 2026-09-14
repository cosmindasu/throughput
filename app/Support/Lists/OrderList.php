<?php

namespace App\Support\Lists;

use App\Enums\OrderStatus;
use App\Models\Membership;
use App\Models\Order;
use App\Models\User;
use App\Support\Exports\ExportableList;
use App\Support\ListQuery;
use App\Support\Permissions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Lista de comenzi — FR-ORD-02 (filtre pe status/cont/interval de dată, paginare pe
 * cursor), imitând `DealList`/`AccountList` (plan §9 task 1).
 */
final class OrderList extends ResourceList implements ExportableList
{
    /** @return list<string> */
    protected function filterKeys(): array
    {
        return ['q', 'status', 'account', 'owner', 'from', 'to'];
    }

    protected function sortableColumns(): array
    {
        return ['order_number', 'grand_total', 'placed_at', 'created_at'];
    }

    protected function defaultSort(): string
    {
        return '-created_at';
    }

    /** US-STOCK-02/US-ORD-01, ca la Accounts/Deals: Agentul pornește pe „My orders". */
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
            'status' => OrderStatus::tryFrom($value) !== null,
            'account' => Str::isUlid($value),
            'owner' => in_array($value, ['me', 'all', 'unassigned'], true) || Str::isUlid($value),
            'from', 'to' => strtotime($value) !== false,
            default => true,
        };
    }

    /**
     * Code review P2-001 — `withExists('shipments')` aici, o SINGURĂ dată pentru toată
     * pagina, ca `OrderPolicy::cancel()` să poată citi `shipments_exists` deja încărcat
     * în loc să repete `shipments()->exists()` per rând (`OrderSummaryResource::can.cancel`,
     * 50 de interogări suplimentare pe o pagină de 50).
     */
    protected function baseQuery(): Builder
    {
        return Order::query()->with(['account:id,name', 'owner:id,name'])->withExists('shipments');
    }

    protected function applyFilters(Builder $query, ListQuery $list, User $user): void
    {
        if (($search = $list->filter('q')) !== null) {
            $query->where('order_number', 'ilike', '%'.addcslashes($search, '%_\\').'%');
        }

        if (($status = $list->filter('status')) !== null) {
            $query->where('status', $status);
        }

        if (($account = $list->filter('account')) !== null) {
            $query->where('account_id', $account);
        }

        if (($from = $list->filter('from')) !== null) {
            $query->whereDate('created_at', '>=', $from);
        }

        if (($to = $list->filter('to')) !== null) {
            $query->whereDate('created_at', '<=', $to);
        }

        match ($owner = $list->filter('owner')) {
            null, 'all' => null,
            'me' => $query->where('owner_user_id', $user->getKey()),
            // Simetric cu AccountList/DealList (ADR-011) — un owner dezactivat,
            // nereatribuit, nu dispare din listă, apare separat sub „Unassigned".
            'unassigned' => $query->where(fn (Builder $unassigned) => $unassigned
                ->whereNotIn('owner_user_id', Membership::query()->where('status', Membership::STATUS_ACTIVE)->select('user_id'))),
            default => $query->where('owner_user_id', Str::lower($owner)),
        };
    }

    /** @return list<string> */
    public function exportHeaders(): array
    {
        return ['Order number', 'Status', 'Account', 'Owner', 'Grand total', 'Currency', 'Placed at', 'Created at'];
    }

    /**
     * @param  Order  $row
     * @return list<string|int|float|null>
     */
    public function exportRow(Model $row): array
    {
        return [
            $row->order_number,
            $row->status->value,
            $row->account?->name,
            $row->owner?->name,
            (float) $row->grand_total,
            $row->currency,
            $row->placed_at?->toIso8601String(),
            $row->created_at?->toIso8601String(),
        ];
    }
}
