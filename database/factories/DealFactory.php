<?php

namespace Database\Factories;

use App\Models\Deal;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Deal>
 */
class DealFactory extends Factory
{
    protected $model = Deal::class;

    public function definition(): array
    {
        return [
            'title' => fake()->randomElement([
                'Annual supply agreement', 'Quarterly restock order', 'New facility opening',
                'Expanded product line', 'Replacement parts contract', 'Volume discount renewal',
                'Emergency reorder', 'New location rollout',
            ]),
            'value' => fake()->randomFloat(2, 850, 185000),
            'currency' => 'USD',
            'expected_close_date' => fake()->dateTimeBetween('now', '+90 days')->format('Y-m-d'),
            'status' => Deal::STATUS_OPEN,
            'lost_reason' => null,
        ];
    }

    public function won(): static
    {
        return $this->state(fn () => ['status' => Deal::STATUS_WON]);
    }

    public function lost(): static
    {
        return $this->state(fn () => [
            'status' => Deal::STATUS_LOST,
            'lost_reason' => fake()->randomElement(['price', 'competition', 'timing', 'other']),
        ]);
    }
}
