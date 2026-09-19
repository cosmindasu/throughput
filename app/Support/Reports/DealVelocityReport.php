<?php

namespace App\Support\Reports;

use App\Models\DealStageEvent;
use App\Models\Pipeline;
use App\Models\ReportDefinition;
use App\Models\Stage;

/**
 * „Deal Velocity by Stage" (specs.md §16.3): timp mediu per etapă și rată de conversie
 * etapă-la-etapă, per pipeline, din `deal_stage_events` (§9.1-9.2).
 *
 * Trei interogări de agregare pe `deal_stage_events`, toate prin Eloquent (scope-ul de
 * tenant al `BelongsToTenant` adaugă `tenant_id = ?` explicit — nu se bazează doar pe RLS),
 * apoi combinarea lor pe etape/pipeline-uri se face în PHP (numărul de etape e mic, sub
 * 10 per pipeline în seed). Planuri verificate cu `EXPLAIN (ANALYZE, BUFFERS)` pe baza de
 * dev (tenant real, 2213-4413 rânduri relevante): Seq Scan pe `deal_stage_events`, 3-6 ms —
 * tabela e mică (8.833 rânduri semănate în TOT tenantul-vitrină), sub pragul p95 de 200 ms
 * (§20.1) cu marjă mare. Niciun index nou nu se justifică la acest volum; vezi raportul
 * lotului K pentru planurile complete.
 *
 * `duration_in_previous_stage_seconds` (§9.2) e scris pe evenimentul care PĂRĂSEȘTE o
 * etapă (`from_stage_id`) — deci media pe `from_stage_id` e timpul mediu petrecut ÎN acea
 * etapă înainte de a avansa sau a muri. O etapă fără nicio tranziție de IEȘIRE (tipic
 * „Won"/"Lost", etape terminale) nu are ce medie să arate — rândul ei apare cu `null`.
 */
final class DealVelocityReport implements BuiltInReport
{
    public function reportType(): string
    {
        return ReportDefinition::TYPE_DEAL_VELOCITY;
    }

    public function title(): string
    {
        return 'Deal Velocity by Stage';
    }

    public function exposesCost(): bool
    {
        return false;
    }

    public function columns(): array
    {
        return ['Pipeline', 'Stage', 'Avg. days in stage', 'Deals reached', 'Conversion to next stage'];
    }

    public function rows(): array
    {
        $pipelines = Pipeline::query()->get()->keyBy('id');

        $stagesByPipeline = Stage::query()
            ->orderBy('position')
            ->get()
            ->groupBy('pipeline_id');

        // Timp mediu petrecut în etapa care se PĂRĂSEȘTE (`from_stage_id`) — nul la
        // evenimentul de creare (§9.2), exclus aici cu `whereNotNull`.
        $avgDurationByStage = DealStageEvent::query()
            ->whereNotNull('from_stage_id')
            ->selectRaw('from_stage_id, avg(duration_in_previous_stage_seconds) as avg_seconds')
            ->groupBy('from_stage_id')
            ->get()
            ->keyBy('from_stage_id');

        // Câte deals DISTINCTE au ajuns vreodată la fiecare etapă (via `to_stage_id`) —
        // baza ratei de conversie: „din câte au ajuns aici, câte au trecut mai departe".
        $reachedByStage = DealStageEvent::query()
            ->selectRaw('to_stage_id, count(distinct deal_id) as deals_reached')
            ->groupBy('to_stage_id')
            ->get()
            ->keyBy('to_stage_id');

        // Câte deals DISTINCTE au tranzitat direct A → B.
        $transitions = DealStageEvent::query()
            ->whereNotNull('from_stage_id')
            ->selectRaw('from_stage_id, to_stage_id, count(distinct deal_id) as deals_transitioned')
            ->groupBy('from_stage_id', 'to_stage_id')
            ->get()
            ->groupBy('from_stage_id');

        $rows = [];

        foreach ($stagesByPipeline as $pipelineId => $stages) {
            $pipelineName = $pipelines->get($pipelineId)?->name ?? 'Unknown pipeline';
            $orderedStages = $stages->sortBy('position')->values();

            foreach ($orderedStages as $index => $stage) {
                $avgSeconds = $avgDurationByStage->get($stage->id)?->avg_seconds;
                $avgDays = $avgSeconds !== null ? round(((float) $avgSeconds) / 86400, 1) : null;
                $reached = (int) ($reachedByStage->get($stage->id)?->deals_reached ?? 0);

                $nextStage = $orderedStages->get($index + 1);
                $conversion = null;

                if ($nextStage !== null && $reached > 0) {
                    $transitioned = $transitions->get($stage->id)
                        ?->firstWhere('to_stage_id', $nextStage->id)
                        ?->deals_transitioned ?? 0;

                    $conversion = round(((int) $transitioned) / $reached * 100, 1);
                }

                $rows[] = [
                    $pipelineName,
                    $stage->name,
                    $avgDays,
                    $reached,
                    $conversion,
                ];
            }
        }

        return $rows;
    }
}
