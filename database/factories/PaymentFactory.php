<?php

namespace Database\Factories;

use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    public function definition(): array
    {
        return [
            'amount' => 0,
            'method' => fake()->randomElement(['bank_transfer', 'bank_transfer', 'check', 'manual']),
        ];
    }
}
