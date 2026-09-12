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
        ];
    }
}
