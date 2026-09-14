<?php

namespace App\Actions\Orders;

use App\Actions\Orders\Concerns\BuildsOrderLines;
use App\Models\Order;
use Illuminate\Support\Facades\DB;

/**
 * Editarea unui draft (§9 task punctul 1) — cont/contact/deal/notițe/owner, plus
 * ÎNLOCUIREA completă a liniilor (nu un merge parțial: formularul retrimite mereu
 * toată lista curentă, ca pe `Deals/Edit`). `OrderPolicy::update()` a verificat deja
 * `status === Draft` înainte ca acest cod să ruleze — niciun `reserved` există încă
 * pe o comandă draft, deci nu e nimic de reconciliat cu stocul aici.
 */
final class UpdateOrderLinesAction
{
    use BuildsOrderLines;

    /**
     * @param  array{account_id: string, contact_id?: ?string, deal_id?: ?string, currency?: ?string, notes?: ?string, owner_user_id?: ?string, lines: list<array<string, mixed>>}  $data
     */
    public function execute(Order $order, array $data): Order
    {
        return DB::transaction(function () use ($order, $data): Order {
            $built = $this->buildLines($data['lines']);

            $order->account_id = $data['account_id'];
            $order->contact_id = $data['contact_id'] ?? null;
            $order->deal_id = $data['deal_id'] ?? null;
            $order->notes = $data['notes'] ?? null;
            $order->subtotal = $built['subtotal'];
            $order->discount_total = $built['discountTotal'];
            $order->grand_total = round($built['subtotal'] - $built['discountTotal'] + (float) $order->shipping_total, 2);

            if (! empty($data['currency'])) {
                $order->currency = $data['currency'];
            }

            if (! empty($data['owner_user_id'])) {
                $order->owner_user_id = $data['owner_user_id'];
            }

            $order->save();

            $order->orderLines()->delete();

            foreach ($built['lines'] as $line) {
                $order->orderLines()->create($line);
            }

            return $order->fresh(['account', 'contact', 'owner', 'orderLines.variant']);
        });
    }
}
