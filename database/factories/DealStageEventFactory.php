<?php

namespace Database\Factories;

use App\Models\DealStageEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DealStageEvent>
 *
 * Câmpurile relaționale (`deal_id`, `from_stage_id`, `to_stage_id`, `changed_by`,
 * `changed_at`) sunt calculate integral de Database\Seeders\Demo\DealsSeeder, care
 * cunoaște traseul complet al deal-ului — factory-ul rămâne subțire, per §9.1
 * (append-only, fără o "definiție" independentă de context care ar avea sens singură).
 */
class DealStageEventFactory extends Factory
{
    protected $model = DealStageEvent::class;

    public function definition(): array
    {
        return [
            'duration_in_previous_stage_seconds' => null,
        ];
    }
}
