<?php

namespace App\Http\Requests\Orders\Concerns;

use App\Models\Variant;
use Closure;
use Illuminate\Support\Collection;

/**
 * Code review P3 — `discount` era validat doar `min:0`, deci `line_total`
 * (`quantity * unit_price - discount`, `BuildsOrderLines::buildLines()`) și
 * `grand_total` puteau ieși negative. Comun `StoreOrderRequest`/`UpdateOrderRequest`:
 * aceeași regulă, același mesaj, pe câmpul liniei.
 *
 * Discountul se compară cu subtotalul liniei (`quantity * unit_price`), calculat cu
 * exact aceeași cădere pe prețul de listă ca `BuildsOrderLines` — `unit_price` absent
 * din cerere ia prețul curent al variantei, niciodată zero, altfel un `discount` de 1
 * ar trece „validarea" pe un subtotal calculat greșit ca 0.
 */
trait ValidatesOrderLineDiscount
{
    /** @var Collection<string, Variant>|null */
    private ?Collection $orderLineVariantsForDiscountCheck = null;

    private function discountWithinLineSubtotalRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if ($value === null || $value === '') {
                return;
            }

            $segments = explode('.', $attribute);
            $index = $segments[1] ?? null;

            if ($index === null) {
                return;
            }

            $line = (array) $this->input("lines.{$index}", []);
            $quantity = isset($line['quantity']) ? (int) $line['quantity'] : 0;
            $lineSubtotal = round($quantity * $this->resolvedOrderLineUnitPrice($line), 2);

            if ((float) $value > $lineSubtotal) {
                $fail("The discount can't exceed the line subtotal ({$lineSubtotal}).");
            }
        };
    }

    /**
     * @param  array<string, mixed>  $line
     */
    private function resolvedOrderLineUnitPrice(array $line): float
    {
        if (isset($line['unit_price']) && $line['unit_price'] !== null && $line['unit_price'] !== '') {
            return round((float) $line['unit_price'], 2);
        }

        $variantId = $line['variant_id'] ?? null;

        if (! is_string($variantId)) {
            return 0.0;
        }

        $variant = $this->orderLineVariantsForDiscountCheck()->get($variantId);

        return $variant !== null ? (float) $variant->price : 0.0;
    }

    /**
     * Toate variantele liniilor, într-o singură interogare, memorată pentru durata
     * cererii — nu una per linie, cât timp o comandă poate avea zeci de linii.
     *
     * @return Collection<string, Variant>
     */
    private function orderLineVariantsForDiscountCheck(): Collection
    {
        if ($this->orderLineVariantsForDiscountCheck !== null) {
            return $this->orderLineVariantsForDiscountCheck;
        }

        $variantIds = collect((array) $this->input('lines', []))
            ->pluck('variant_id')
            ->filter(fn (mixed $id): bool => is_string($id))
            ->unique()
            ->values();

        return $this->orderLineVariantsForDiscountCheck = Variant::query()
            ->whereIn('id', $variantIds)
            ->get(['id', 'price'])
            ->keyBy('id');
    }
}
