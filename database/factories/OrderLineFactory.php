<?php

namespace Database\Factories;

use App\Models\OrderLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderLine>
 */
class OrderLineFactory extends Factory
{
    protected $model = OrderLine::class;

    public function definition(): array
    {
        return [
            'quantity' => fake()->numberBetween(1, 20),
            'discount' => 0,
            'quantity_fulfilled' => 0,
        ];
    }
}
