<?php

namespace App\Support\Members;

use App\Models\Deal;
use App\Models\Membership;
use App\Models\Order;

/**
 * FR-TEN-05 — indicatorul numeric permanent din navigație („Unassigned"), cât timp
 * vederea nu e goală. Aceeași definiție ca `OpenRecordCounts` (deals deschise + comenzi
 * active), dar pe TOȚI membrii dezactivați ai tenantului, nu pe unul singur.
 *
 * Cost măsurat (raportul pachetului): două `count()` pe `deals`/`orders`, fiecare cu un
 * `whereNotIn` pe subquery-ul `memberships` active — exact tiparul deja folosit de
 * `AccountList`/`DealList`/`OrderList` pentru filtrul `owner=unassigned` (ADR-011), deci
 * planul de execuție e deja cunoscut din acele liste. Apelat o dată per cerere, doar pentru
 * Owner/Manager (`HandleInertiaRequests`), niciodată pentru restul rolurilor.
 */
final class UnassignedRecordsCounter
{
    public static function count(): int
    {
        if (! app()->bound('tenant')) {
            return 0;
        }

        $activeMemberIds = Membership::query()->where('status', Membership::STATUS_ACTIVE)->select('user_id');

        $deals = Deal::query()
            ->where('status', Deal::STATUS_OPEN)
            ->whereNotIn('owner_user_id', $activeMemberIds)
            ->count();

        $orders = Order::query()
            ->whereIn('status', OpenRecordCounts::activeOrderStatuses())
            ->whereNotIn('owner_user_id', $activeMemberIds)
            ->count();

        return $deals + $orders;
    }
}
