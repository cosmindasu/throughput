<?php

namespace Tests\Concerns;

use App\Models\Pipeline;
use App\Models\Stage;
use App\Models\Tenant;

/**
 * Fixture minimă de pipeline pentru testele Pachetului C (Deals + kanban) — cele 6 etape
 * canonice din specs.md §9.3 (New, Qualified, Proposal Sent, Negotiation, Won, Lost).
 *
 * Fișier NOU, doar pentru testele acestui pachet — nu extinde `Tests\TestCase` (folosit
 * de toți cei 7 agenți în paralel), ca să nu existe niciun risc de conflict pe un fișier
 * comun.
 *
 * Apelanții rulează asta ÎN `TenantContext::run()`: `Pipeline`/`Stage` au RLS, iar
 * politica se aplică și la INSERT (`.ai/rules/tenancy.md`).
 */
trait CreatesPipelines
{
    /**
     * @return array{pipeline: Pipeline, stages: array<string, Stage>}
     */
    protected function makeDefaultPipeline(Tenant $tenant): array
    {
        $pipeline = Pipeline::query()->create(['name' => 'Wholesale Sales', 'is_default' => true]);

        $definitions = [
            ['name' => 'New', 'position' => 1, 'is_won' => false, 'is_lost' => false, 'probability' => 10],
            ['name' => 'Qualified', 'position' => 2, 'is_won' => false, 'is_lost' => false, 'probability' => 25],
            ['name' => 'Proposal Sent', 'position' => 3, 'is_won' => false, 'is_lost' => false, 'probability' => 50],
            ['name' => 'Negotiation', 'position' => 4, 'is_won' => false, 'is_lost' => false, 'probability' => 75],
            ['name' => 'Won', 'position' => 5, 'is_won' => true, 'is_lost' => false, 'probability' => 100],
            ['name' => 'Lost', 'position' => 6, 'is_won' => false, 'is_lost' => true, 'probability' => 0],
        ];

        $stages = [];

        foreach ($definitions as $definition) {
            $stages[$definition['name']] = Stage::query()->create([
                'pipeline_id' => $pipeline->getKey(),
                ...$definition,
            ]);
        }

        return ['pipeline' => $pipeline, 'stages' => $stages];
    }
}
