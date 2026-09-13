<?php

namespace App\Actions\Pipeline;

use App\Models\Pipeline;
use App\Models\Stage;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * FR-DEAL-02 — reordonarea etapelor pe lista de configurare (drag NATIV + „Move up"/„Move
 * down" de la tastatură, WCAG 2.2 SC 2.5.7, §20.3). Primește lista ORDONATĂ completă de
 * id-uri, nu o mutare punctuală (index sursă/destinație) — clientul trimite mereu starea
 * finală dorită, ca serverul să nu recompună o mutare din diferență.
 *
 * Validare ÎNAINTE de orice scriere: id-uri străine (alt tenant — invizibile prin global
 * scope, deci tratate ca „lipsă"), duplicate sau lipsă produc `ValidationException` (422) și
 * NIMIC nu se scrie. Global scope-ul + RLS pe `Stage` garantează că `$pipeline->stages()` nu
 * vede niciodată o etapă din alt tenant, deci un id străin în payload cade automat în
 * categoria „nu aparține acestui pipeline".
 */
final class ReorderStagesAction
{
    /**
     * @param  list<string>  $orderedStageIds
     */
    public function execute(Pipeline $pipeline, array $orderedStageIds): void
    {
        $existingIds = $pipeline->stages()->pluck('id')->all();

        if (count(array_unique($orderedStageIds)) !== count($orderedStageIds)) {
            throw ValidationException::withMessages([
                'stage_ids' => 'The stage order cannot repeat the same stage twice.',
            ]);
        }

        $sortedRequested = $orderedStageIds;
        sort($sortedRequested);
        sort($existingIds);

        if ($sortedRequested !== $existingIds) {
            throw ValidationException::withMessages([
                'stage_ids' => 'The stage order must list every stage of this pipeline, exactly once, and no others.',
            ]);
        }

        DB::transaction(function () use ($orderedStageIds): void {
            // P2-003: dacă UPDATE-urile rulează în ordinea din payload, două reordonări
            // concurente cu payload-uri "în oglindă" pot bloca rândurile în ordine inversă
            // una față de cealaltă și aștepta reciproc — Postgres detectează asta cu
            // `40P01 deadlock_detected` și una dintre cereri primește 500. Construim harta
            // id → poziție și aplicăm UPDATE-urile sortate după id, ca ordinea de achiziție a
            // lock-urilor să fie ACEEAȘI pentru orice cerere, indiferent de ordinea din payload.
            $positionByStageId = [];
            foreach (array_values($orderedStageIds) as $index => $stageId) {
                $positionByStageId[$stageId] = $index + 1;
            }
            ksort($positionByStageId, SORT_STRING);

            foreach ($positionByStageId as $stageId => $position) {
                Stage::query()->whereKey($stageId)->update(['position' => $position]);
            }
        });
    }
}
