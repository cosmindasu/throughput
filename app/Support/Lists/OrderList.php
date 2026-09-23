<?php

namespace App\Support\Lists;

use App\Enums\OrderStatus;
use App\Models\Membership;
use App\Models\Order;
use App\Models\User;
use App\Support\Exports\ExportableList;
use App\Support\ListQuery;
use App\Support\Members\OpenRecordCounts;
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
    /**
     * Pseudo-status pentru „comenzi active" (plan §9, reatribuirea la dezactivare,
     * US-TEN-03; și indicatorul „Unassigned", FR-TEN-05) — `draft`, `confirmed` sau
     * `partially_fulfilled`. Nu e un `OrderStatus` real: filtrul `status` existent
     * acceptă o singură valoare exactă, iar un singur rând `bulk_operations` (BR-BULK-04)
     * nu poate exprima „status IN (...)" altfel.
     *
     * Construit pentru `MembersController`/`UnassignedController`, dar `accepts()` de mai
     * jos nu-l poate distinge de un filtru venit din URL — orice cerere poate trimite
     * `?filter[status]=active`. Acceptabil, deliberat: valoarea doar AGREGĂ trei statusuri
     * deja vizibile separat pe `Orders/Index`, cu aceeași izolare de tenant/RLS ca restul
     * filtrelor — nu expune nimic ce n-ar fi vizibil deja prin trei cereri separate.
     */
    public const STATUS_ACTIVE = 'active';

    /** @return list<string> */
    protected function filterKeys(): array
    {
        return ['q', 'status', 'account', 'owner', 'from', 'to'];
    }

    /**
     * PERF-06 (audit 2026-09-23) — sortările secundare (`grand_total`, `placed_at`) NU au
     * index dedicat: măsurat sub RLS, ca `throughput_app`, pe seed-ul de volum, între 8 și
     * 88 ms, sub pragul p95 de 200 ms (`git show 9b57ef6`). Decizia proprietarului a fost
     * să nu adauge index fără o măsurătoare care s-o justifice — dacă cifrele astea se
     * schimbă (volum mult mai mare, plângeri reale de latență), re-măsoară înainte de a
     * adăuga unul.
     */
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
            'status' => $value === self::STATUS_ACTIVE || OrderStatus::tryFrom($value) !== null,
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
            if ($status === self::STATUS_ACTIVE) {
                $query->whereIn('status', OpenRecordCounts::activeOrderStatuses());
            } else {
                $query->where('status', $status);
            }
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
        // FR-I18N-04 — vezi nota din `AccountList::exportHeaders()`. Comenzile nu se
        // importă, deci aici nu există constrângerea de potrivire cu aliasurile.
        return [
            __('exports.orders.order_number'),
            __('exports.orders.status'),
            __('exports.orders.account'),
            __('exports.orders.owner'),
            __('exports.orders.grand_total'),
            __('exports.orders.currency'),
            __('exports.orders.placed_at'),
            __('exports.orders.created_at'),
        ];
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
