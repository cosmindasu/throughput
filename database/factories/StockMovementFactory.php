<?php

namespace Database\Factories;

use App\Models\StockMovement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockMovement>
 *
 * Registru append-only (ADR-004) — Database\Seeders\Demo\StockAndOrdersSeeder calculează
 * `delta`/`reason`/`ref_*` din ledger-ul de stoc pe care îl întreține (recepții inițiale,
 * vânzări legate de shipment-uri, transferuri, ajustări). Factory-ul rămâne o definiție
 * generică (mișcare de recepție), utilă independent (teste).
 */
class StockMovementFactory extends Factory
{
    protected $model = StockMovement::class;

    public function definition(): array
    {
        return [
            'delta' => fake()->numberBetween(50, 500),
            'reason' => 'receipt',
            'ref_type' => null,
            'ref_id' => null,
            'note' => null,
        ];
    }

    public function sale(): static
    {
        return $this->state(fn () => ['delta' => -fake()->numberBetween(1, 50), 'reason' => 'sale']);
    }

    public function adjustment(string $note): static
    {
        return $this->state(fn () => ['reason' => 'adjustment', 'note' => $note]);
    }
}
