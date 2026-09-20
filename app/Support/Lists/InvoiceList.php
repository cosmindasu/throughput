<?php

namespace App\Support\Lists;

use App\Models\Invoice;
use App\Models\User;
use App\Support\ListQuery;
use App\Support\Permissions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Lista de facturi — §12.1, „listă Invoices (paginare pe cursor, filtre ca la Orders)".
 * Imită `OrderList` (plan §9 task 1) — aceeași bază `ResourceList`, fără nicio funcție de
 * export (FR-BILL-03, „export facturi în masă", nu e livrată de acest lot — vezi
 * CONTRAZICERI din raportul livrat), deci `InvoiceList` nu implementează
 * `App\Support\Exports\ExportableList`, spre deosebire de `OrderList`.
 */
final class InvoiceList extends ResourceList
{
    /** @return list<string> */
    protected function filterKeys(): array
    {
        return ['q', 'status', 'account', 'from', 'to'];
    }

    protected function sortableColumns(): array
    {
        return ['invoice_number', 'total', 'balance_due', 'due_date', 'created_at'];
    }

    protected function defaultSort(): string
    {
        return '-created_at';
    }

    protected function accepts(string $key, string $value): bool
    {
        return match ($key) {
            'status' => in_array($value, [
                Invoice::STATUS_DRAFT, Invoice::STATUS_SENT, Invoice::STATUS_PAID,
                Invoice::STATUS_OVERDUE, Invoice::STATUS_VOID,
            ], true),
            'account' => Str::isUlid($value),
            'from', 'to' => strtotime($value) !== false,
            default => true,
        };
    }

    protected function baseQuery(): Builder
    {
        return Invoice::query()->with(['order:id,order_number,account_id,owner_user_id', 'order.account:id,name']);
    }

    /**
     * §7.4, rândul „Facturi (către clienți)", litera „R*" la Agent — spre deosebire de
     * `OrderList` (unde Agentul alege între „My orders"/„All orders"), aici restricția
     * e NECONDIȚIONATĂ: matricea nu-i dă Agentului un comutator, doar vederea îngustă.
     */
    protected function applyFilters(Builder $query, ListQuery $list, User $user): void
    {
        if (Permissions::restrictedToOwnRecords($user)) {
            $query->whereHas('order', fn (Builder $orders) => $orders->where('owner_user_id', $user->getKey()));
        }

        if (($search = $list->filter('q')) !== null) {
            $query->where('invoice_number', 'ilike', '%'.addcslashes($search, '%_\\').'%');
        }

        if (($status = $list->filter('status')) !== null) {
            $query->where('status', $status);
        }

        if (($account = $list->filter('account')) !== null) {
            $query->whereHas('order', fn (Builder $orders) => $orders->where('account_id', $account));
        }

        if (($from = $list->filter('from')) !== null) {
            $query->whereDate('created_at', '>=', $from);
        }

        if (($to = $list->filter('to')) !== null) {
            $query->whereDate('created_at', '<=', $to);
        }
    }
}
