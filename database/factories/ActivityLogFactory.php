<?php

namespace Database\Factories;

use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ActivityLog>
 *
 * Nefolosit direct de seed-ul de volum (care trece prin
 * Database\Seeders\Support\ActivityLogRecorder, ca formatul rândului să fie un singur
 * loc de întreținut) — prezent pentru folosire independentă (teste unitare pe modelul
 * ActivityLog, tinker).
 */
class ActivityLogFactory extends Factory
{
    protected $model = ActivityLog::class;

    public function definition(): array
    {
        return [
            'action' => 'created',
            'ip_address' => fake()->ipv4(),
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        ];
    }
}
