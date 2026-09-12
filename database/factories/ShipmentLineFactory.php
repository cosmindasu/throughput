<?php

namespace Database\Factories;

use App\Models\ShipmentLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShipmentLine>
 */
class ShipmentLineFactory extends Factory
{
    protected $model = ShipmentLine::class;

    public function definition(): array
    {
        return [
            'quantity' => 1,
        ];
    }
}
