<?php

namespace Database\Factories;

use App\Models\Variant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Variant>
 */
class VariantFactory extends Factory
{
    protected $model = Variant::class;

    public function definition(): array
    {
        // Sumă nerotundă (specs.md §21.2): preț de listă cu 2 zecimale reale, nu capete rotunde.
        $price = fake()->randomFloat(2, 0.45, 640);

        return [
            'sku' => strtoupper(fake()->unique()->bothify('SKU-????-####')),
            'attributes' => [],
            'price' => $price,
            // Marja e ascunsă pentru Agent/Viewer la nivel de UI (§7.4) — aici doar valoarea.
            'cost' => round($price * fake()->randomFloat(2, 0.45, 0.75), 2),
            'weight' => fake()->randomFloat(3, 0.01, 45),
            'is_active' => true,
            // `null` implicit (fără alertă, FR-STOCK-02) — vezi state-ul `lowStockThreshold()`.
            'low_stock_threshold' => null,
        ];
    }

    /**
     * FR-STOCK-02 — prag explicit de „low stock". Fără argument, alege un prag mic dar
     * plauzibil (5-30); testele care vor un scenariu la limită îl suprascriu direct pe
     * modelul construit (`Variant::factory()->lowStockThreshold(10)->create()`).
     */
    public function lowStockThreshold(?int $threshold = null): static
    {
        return $this->state(fn () => ['low_stock_threshold' => $threshold ?? fake()->numberBetween(5, 30)]);
    }
}
