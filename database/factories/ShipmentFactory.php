<?php

namespace Database\Factories;

use App\Models\Shipment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Shipment>
 */
class ShipmentFactory extends Factory
{
    protected $model = Shipment::class;

    public function definition(): array
    {
        return [
            'carrier' => 'demo',
            'service_level' => fake()->randomElement(['Ground', 'Ground', 'Ground', '2-Day', 'Overnight', 'Freight']),
            'status' => Shipment::STATUS_LABEL_PURCHASED,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn () => ['status' => Shipment::STATUS_LABEL_PENDING, 'tracking_number' => null, 'label_url' => null]);
    }

    public function failed(): static
    {
        return $this->state(fn () => ['status' => Shipment::STATUS_LABEL_FAILED, 'tracking_number' => null, 'label_url' => null]);
    }
}
