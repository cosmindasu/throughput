<?php

namespace Database\Factories;

use App\Models\Stage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Stage>
 */
class StageFactory extends Factory
{
    protected $model = Stage::class;

    /**
     * Valori generice — seed-ul de volum (Database\Seeders\Demo\CatalogSeeder) suprascrie
     * `name`/`position`/`is_won`/`is_lost`/`probability` cu cele 6 etape canonice din
     * specs.md §9.3 (New, Qualified, Proposal Sent, Negotiation, Won, Lost).
     */
    public function definition(): array
    {
        return [
            'name' => 'New',
            'position' => 1,
            'is_won' => false,
            'is_lost' => false,
            'probability' => 10,
        ];
    }

    public function won(): static
    {
        return $this->state(fn () => ['name' => 'Won', 'is_won' => true, 'probability' => 100]);
    }

    public function lost(): static
    {
        return $this->state(fn () => ['name' => 'Lost', 'is_lost' => true, 'probability' => 0]);
    }
}
