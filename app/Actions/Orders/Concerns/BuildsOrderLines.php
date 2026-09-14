<?php

namespace App\Actions\Orders\Concerns;

use App\Models\Variant;
use Illuminate\Support\Collection;

/**
 * Partajat de `CreateOrderAction` și `UpdateOrderLinesAction`: din liniile brute
 * validate de `StoreOrderRequest`/`UpdateOrderRequest` (`variant_id`, `quantity`,
 * `unit_price?`, `discount?`) produce rândurile gata de `order_lines` + totalurile
 * comenzii (§11.1 — `subtotal`/`discount_total`/`grand_total` „calculate din order_lines").
 *
 * US-ORD-01 — „fiecare linie precompletează unit_price din prețul de listă curent al
 * variantei": `unit_price` lipsă (nu trimis de formular) cade pe `variant->price`,
 * niciodată pe zero. `description` e instantaneul cerut de §11.1 (nu urmărește
 * redenumiri ulterioare ale variantei/produsului).
 */
trait BuildsOrderLines
{
    /**
     * @param  list<array{variant_id: string, quantity: int, unit_price?: numeric-string|float|null, discount?: numeric-string|float|null}>  $rawLines
     * @return array{lines: list<array<string, mixed>>, subtotal: float, discountTotal: float}
     */
    private function buildLines(array $rawLines): array
    {
        if ($rawLines === []) {
            return ['lines' => [], 'subtotal' => 0.0, 'discountTotal' => 0.0];
        }

        $variantIds = collect($rawLines)->pluck('variant_id')->unique()->values();

        /** @var Collection<string, Variant> $variants */
        $variants = Variant::query()->with('product:id,name')->whereIn('id', $variantIds)->get()->keyBy('id');

        $subtotal = 0.0;
        $discountTotal = 0.0;
        $lines = [];

        foreach ($rawLines as $raw) {
            /** @var Variant $variant */
            $variant = $variants->get($raw['variant_id']);

            $quantity = (int) $raw['quantity'];
            $unitPrice = isset($raw['unit_price']) && $raw['unit_price'] !== null && $raw['unit_price'] !== ''
                ? round((float) $raw['unit_price'], 2)
                : (float) $variant->price;
            $discount = isset($raw['discount']) && $raw['discount'] !== null && $raw['discount'] !== ''
                ? round((float) $raw['discount'], 2)
                : 0.0;

            $lineSubtotal = round($quantity * $unitPrice, 2);
            $lineTotal = round($lineSubtotal - $discount, 2);

            $lines[] = [
                'variant_id' => $variant->getKey(),
                'description' => trim(($variant->product?->name ?? 'Product').' — '.$variant->sku),
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'discount' => $discount,
                'line_total' => $lineTotal,
            ];

            $subtotal += $lineSubtotal;
            $discountTotal += $discount;
        }

        return ['lines' => $lines, 'subtotal' => round($subtotal, 2), 'discountTotal' => round($discountTotal, 2)];
    }
}
