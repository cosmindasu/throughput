<?php

namespace Database\Factories;

use App\Models\InventoryLevel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InventoryLevel>
 *
 * Proiecție materializată (ADR-004) — Database\Seeders\Demo\StockAndOrdersSeeder scrie
 * `on_hand`/`reserved` finale, calculate din ledger-ul `stock_movements` pe care îl
 * întreține, nu din valori independente ale acestei definiții.
 */
class InventoryLevelFactory extends Factory
{
    protected $model = InventoryLevel::class;

    public function definition(): array
    {
        return [
            'on_hand' => 0,
            'reserved' => 0,
        ];
    }
}
