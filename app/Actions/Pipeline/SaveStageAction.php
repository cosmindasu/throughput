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

        return DB::transaction(function () use ($pipeline, $data, $stage): Stage {
            // P2-002: verificare-apoi-scriere (nume unic, cel mult un Won/un Lost,
            // `max('position')`) rulând fără lock lasă o fereastră de cursă — două cereri
            // concurente pot trece amândouă de "nu există deja un Won" înainte ca vreuna să
            // scrie, și pipeline-ul rămâne cu două etape Won. Blocăm rândul PIPELINE-ului
            // (resursa comună a tuturor etapelor lui), nu etapa în sine — o etapă nouă încă
            // nu are rând de blocat. Verificările trebuie mutate DUPĂ acest lock: a doua
            // cerere concurentă așteaptă commit-ul primei și vede deja rezultatul ei.
            //
            // Code review P1-001 — `->lock('for no key update')`, NU `lockForUpdate()`
            // (`FOR UPDATE`): acest cod nu scrie nimic pe rândul `Pipeline` însuși, doar
            // îl folosește ca blocare a unei invariante care trăiește pe etapele lui.
            // `FOR UPDATE` ar intra în conflict cu `FOR KEY SHARE`, blocarea luată la
            // verificarea FK a oricărui INSERT/UPDATE într-un rând `stages`, deci ar opri
            // acele scrieri (inclusiv `create()`/`update()` de mai jos, în ALTĂ tranzacție)
            // până la commit — tranzacția ține toată cererea (ADR-013). `FOR NO KEY
            // UPDATE` serializează la fel două salvări concurente de etape, fără să
            // blocheze copiii. Vezi `.ai/rules/tenancy.md`, secțiunea „Blocarea unui rând
            // părinte".
            Pipeline::query()->whereKey($pipeline->getKey())->lock('for no key update')->first();

            $this->ensureNameIsUnique($pipeline, $data['name'], $stage);

            if ($data['is_won']) {
                $this->ensureNoOtherStageHasFlag($pipeline, 'is_won', $stage, 'Won');
            }

            if ($data['is_lost']) {
                $this->ensureNoOtherStageHasFlag($pipeline, 'is_lost', $stage, 'Lost');
            }

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
