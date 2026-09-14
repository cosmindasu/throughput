<?php

namespace App\Actions\Orders;

use App\Actions\Orders\Concerns\BuildsOrderLines;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Crearea unui draft de comandă (§11.2 pas 2, US-ORD-01) — `order_number` și
 * `placed_at` rămân `null` până la `ConfirmOrderAction` (BR-ORD-02), iar liniile
 * inițiale sunt OPȚIONALE: un draft se poate salva gol și primi linii din `edit()`.
 */
final class CreateOrderAction
{
    use BuildsOrderLines;

    /**
     * @param  array{account_id: string, contact_id?: ?string, deal_id?: ?string, currency?: ?string, notes?: ?string, owner_user_id?: ?string, lines?: list<array<string, mixed>>}  $data
     */
    public function execute(array $data, User $by): Order
    {
        return DB::transaction(function () use ($data, $by): Order {
            $built = $this->buildLines($data['lines'] ?? []);

            $order = new Order([
                'account_id' => $data['account_id'],
                'contact_id' => $data['contact_id'] ?? null,
                'deal_id' => $data['deal_id'] ?? null,
                'owner_user_id' => $data['owner_user_id'] ?? $by->getKey(),
                'status' => OrderStatus::Draft,
                'currency' => $data['currency'] ?? 'USD',
                'subtotal' => $built['subtotal'],
                'discount_total' => $built['discountTotal'],
                'shipping_total' => 0,
                'grand_total' => round($built['subtotal'] - $built['discountTotal'], 2),
                'notes' => $data['notes'] ?? null,
            ]);
            // `created_by` nu e în #[Fillable] (ca și pe Account/Deal) — mass-assignment
            // ar accepta orice id trimis de client.
            $order->created_by = $by->getKey();
            $order->save();

            foreach ($built['lines'] as $line) {
                $order->orderLines()->create($line);
            }

            return $order->fresh(['account', 'contact', 'owner', 'orderLines.variant']);
        });
    }
}
