<?php

namespace Database\Factories;

use App\Models\Location;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Location>
 */
class LocationFactory extends Factory
{
    protected $model = Location::class;

    public function definition(): array
    {
        return [
            'name' => 'Main Warehouse',
            'is_default' => true,
        ];
    }

    public function overflow(): static
    {
        return $this->state(fn () => ['name' => 'Overflow Storage', 'is_default' => false]);
    }
}
