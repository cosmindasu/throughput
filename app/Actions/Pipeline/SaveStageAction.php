<?php

namespace App\Actions\Pipeline;

use App\Models\Pipeline;
use App\Models\Stage;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creare/editare de etapă (FR-DEAL-02), cu regulile de business din specs.md §9.2/§9.5 care nu
 * încap într-un `FormRequest::rules()` simplu, fiindcă depind de CELELALTE etape ale
 * pipeline-ului, nu doar de câmpurile trimise:
 *
 *  - Numele e unic ÎN PIPELINE (nu global — două pipeline-uri diferite pot avea o etapă „New").
 *  - Cel mult o etapă `is_won` și cel mult una `is_lost` per pipeline.
 *  - O etapă nu poate fi simultan `is_won` și `is_lost`.
 *
 * `ValidationException` aici produce exact același răspuns 422 ca o regulă din
 * `FormRequest::rules()` — controllerul nu are nevoie să prindă nimic.
 */
final class SaveStageAction
{
    /**
     * @param  array{name: string, probability: ?int, is_won: bool, is_lost: bool}  $data
     */
    public function execute(Pipeline $pipeline, array $data, ?Stage $stage = null): Stage
    {
        if ($data['is_won'] && $data['is_lost']) {
            throw ValidationException::withMessages([
                'is_lost' => 'A stage cannot be marked as both Won and Lost.',
            ]);
        }

        $this->ensureNameIsUnique($pipeline, $data['name'], $stage);

        if ($data['is_won']) {
            $this->ensureNoOtherStageHasFlag($pipeline, 'is_won', $stage, 'Won');
        }

        if ($data['is_lost']) {
            $this->ensureNoOtherStageHasFlag($pipeline, 'is_lost', $stage, 'Lost');
        }

        return DB::transaction(function () use ($pipeline, $data, $stage): Stage {
            if ($stage === null) {
                // Etapă nouă: se adaugă la finalul ordinii curente. Reordonarea propriu-zisă
                // e responsabilitatea endpoint-ului dedicat (`ReorderStagesAction`), nu a
                // formularului de creare/editare.
                $nextPosition = (int) $pipeline->stages()->max('position') + 1;

                return $pipeline->stages()->create([...$data, 'position' => $nextPosition]);
            }

            $stage->update($data);

            return $stage->fresh();
        });
    }

    private function ensureNameIsUnique(Pipeline $pipeline, string $name, ?Stage $ignoring): void
    {
        $exists = $pipeline->stages()
            ->where('name', $name)
            ->when($ignoring, fn ($query) => $query->whereKeyNot($ignoring->getKey()))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'name' => "A stage named \"{$name}\" already exists in this pipeline.",
            ]);
        }
    }

    private function ensureNoOtherStageHasFlag(Pipeline $pipeline, string $flag, ?Stage $ignoring, string $label): void
    {
        $exists = $pipeline->stages()
            ->where($flag, true)
            ->when($ignoring, fn ($query) => $query->whereKeyNot($ignoring->getKey()))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                $flag => "This pipeline already has a stage marked as {$label}.",
            ]);
        }
    }
}
