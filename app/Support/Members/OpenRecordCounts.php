<?php

namespace App\Support\Members;

use App\Enums\OrderStatus;
use App\Models\Deal;
use App\Models\Order;

/**
 * §6.4.1, BR-TEN-06 — „12 open deals and 3 active orders": le numărul exact afișat în
 * confirmarea de dezactivare (Gherkin US-TEN-03) și folosit ca filtru pentru cele trei
 * operații de reasignare (plan §9). „Deschise"/„active" = deals `open` + comenzi
 * `draft`/`confirmed`/`partially_fulfilled`, DECIZIE deja luată (nu conturi — vezi raportul
 * pachetului, care notează contradicția cu BR-TEN-05 în Change Log).
 *
 * Interogări GRUPATE pe `owner_user_id`, o singură dată pentru toți membrii activi ai
 * paginii `Settings/Members` — nu o interogare per rând (N membri, 2 interogări, nu 2×N).
 */
final class OpenRecordCounts
{
    /**
     * @param  list<string>  $userIds
     * @return array<string, array{deals: int, orders: int, total: int}>
     */
    public static function forUsers(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $deals = Deal::query()
            ->whereIn('owner_user_id', $userIds)
            ->where('status', Deal::STATUS_OPEN)
            ->selectRaw('owner_user_id, count(*) as aggregate')
            ->groupBy('owner_user_id')
            ->pluck('aggregate', 'owner_user_id');

        $orders = Order::query()
            ->whereIn('owner_user_id', $userIds)
            ->whereIn('status', self::activeOrderStatuses())
            ->selectRaw('owner_user_id, count(*) as aggregate')
            ->groupBy('owner_user_id')
            ->pluck('aggregate', 'owner_user_id');

        return collect($userIds)->mapWithKeys(function (string $userId) use ($deals, $orders): array {
            $dealsCount = (int) ($deals[$userId] ?? 0);
            $ordersCount = (int) ($orders[$userId] ?? 0);

            return [$userId => [
                'deals' => $dealsCount,
                'orders' => $ordersCount,
                'total' => $dealsCount + $ordersCount,
            ]];
        })->all();
    }

    /**
     * @return array{deals: int, orders: int, total: int}
     */
    public static function forUser(string $userId): array
    {
        return self::forUsers([$userId])[$userId] ?? ['deals' => 0, 'orders' => 0, 'total' => 0];
    }

    /** @return list<string> */
    public static function activeOrderStatuses(): array
    {
        return [OrderStatus::Draft->value, OrderStatus::Confirmed->value, OrderStatus::PartiallyFulfilled->value];
    }
}
