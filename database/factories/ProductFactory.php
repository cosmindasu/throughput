<?php

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    /**
     * Valori generice — seed-ul de volum (Database\Seeders\Demo\CatalogSeeder) suprascrie
     * `name`/`category` cu arhetipuri specifice verticalei tenantului (fasteners/
     * hydraulics/foodservice).
     */
    public function definition(): array
    {
        return [
            'name' => fake()->words(2, true),
            'category' => fake()->word(),
            'unit_of_measure' => fake()->randomElement(['each', 'box', 'pallet']),
            'is_active' => true,
        ];
    }
}
