<?php

namespace Database\Factories;

use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    protected $model = Order::class;

    public function definition(): array
    {
        return [
            'status' => Order::STATUS_DRAFT,
            'currency' => 'USD',
            'subtotal' => 0,
            'discount_total' => 0,
            'shipping_total' => 0,
            'grand_total' => 0,
            'notes' => fake()->boolean(10) ? fake()->randomElement([
                'Rush order — customer requested expedited shipping.',
                'Will call for pickup, do not ship.',
                'Confirm freight class before dispatch.',
                'Recurring monthly restock.',
                'Customer requires proof of delivery.',
            ]) : null,
        ];
    }
}
