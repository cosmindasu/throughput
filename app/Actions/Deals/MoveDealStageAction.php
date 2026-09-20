<?php

namespace App\Actions\Deals;

use App\Models\Deal;
use App\Models\DealStageEvent;
use App\Models\Stage;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * `PATCH /deals/{deal}/stage` — §9.3, pas cu pas. Singurul loc care scrie o tranziție în
 * `deal_stage_events` (`CreateDealAction` scrie doar prima, cea de la creare, cu
 * `from_stage_id = null`).
 *
 * Toate refuzurile sunt `ValidationException`, nu `AuthorizationException`: sunt reguli
 * de STARE („etapa asta e validă pentru ACEST deal ACUM"), nu de DREPT („poate omul ăsta
 * muta ORICE deal") — dreptul brut (`deals.move_stage` + ownership) e verificat înainte,
 * în `DealPolicy::moveStage()`, de către controller. Un 422 cu mesaj pe câmp ajunge în UI
 * ca eroare de formular; un 403 ar fi opac (§7.3 — „aplicația stricată").
 */
final class MoveDealStageAction
{
    /** @var list<string> FR-DEAL-03 — lista închisă de motive de pierdere. */
    private const LOST_REASONS = ['price', 'competition', 'timing', 'other'];

    public function execute(Deal $deal, Stage $to, User $by, ?string $lostReason = null): Deal
    {
        return DB::transaction(function () use ($deal, $to, $by, $lostReason): Deal {
            // `lockForUpdate`: două request-uri concurente pe același deal (dublu-clic,
            // sau drag urmat imediat de „Move to stage…") nu trebuie să insereze două
            // evenimente pe baza aceleiași stări „dinainte". Garanția vine STRICT din
            // acest lock, în tranzacția cererii (al doilea request așteaptă la rândul
            // lui `firstOrFail()` până la commit-ul primului, apoi vede `stage_id` deja
            // actualizat). Nu există test automat care exercită real concurența (P3-e,
            // code review): două request-uri simultane pe același deal nu sunt practice
            // într-un singur fir Pest — ar cere două conexiuni DB separate, coordonate
            // manual în jurul lock-ului, ceea ce testele acestui pachet nu fac.
            /** @var Deal $locked */
            $locked = Deal::query()->whereKey($deal->getKey())->lockForUpdate()->firstOrFail();

            // Etapa țintă trebuie să aparțină pipeline-ului deal-ului (§9.2) — un ULID
            // valid dintr-un alt pipeline al ACELUIAȘI tenant nu e prins de RLS/global
            // scope (ambele sunt scopate pe tenant, nu pe pipeline), deci verificarea
            // structurală stă aici, explicit.
            if ($to->pipeline_id !== $locked->pipeline_id) {
                throw ValidationException::withMessages([
                    'to_stage_id' => trans('rules.deals.stage_wrong_pipeline'),
                ]);
            }

            if ($to->getKey() === $locked->stage_id) {
                throw ValidationException::withMessages([
                    'to_stage_id' => trans('rules.deals.already_on_stage'),
                ]);
            }

            // Mesaj EXACT cerut de criteriul de acceptanță §9.3 — testul de contract
            // și UI-ul depind de text, nu doar de statusul HTTP. Cheia catalogului
            // păstrează exact același șir, fără punct final (vezi comentariul din
            // `lang/en/rules.php`).
            if ($to->is_won && $locked->value === null) {
                throw ValidationException::withMessages([
                    'to_stage_id' => trans('rules.deals.value_required_for_won'),
                ]);
            }

            if ($to->is_lost && ! in_array($lostReason, self::LOST_REASONS, true)) {
                throw ValidationException::withMessages([
                    'lost_reason' => trans('rules.deals.lost_reason_required'),
                ]);
            }

            // BR-DEAL-02: calculată O SINGURĂ DATĂ, din evenimentul anterior al ACELUIAȘI
            // deal — niciodată recalculată retroactiv. `null` dacă acesta e primul
            // eveniment vizibil (nu ar trebui, `CreateDealAction` inserează mereu unul,
            // dar un deal orfan de test nu trebuie să arunce aici).
            $previousEvent = DealStageEvent::query()
                ->where('deal_id', $locked->getKey())
                ->orderByDesc('changed_at')
                ->orderByDesc('id')
                ->first();

            $changedAt = now();
            // `diffInSeconds()` întoarce `float` din Carbon 3 (precizie de sub-secundă) —
            // coloana e `bigint`, deci se rotunjește explicit, nu se lasă driverul PDO
            // să eșueze cu „invalid input syntax for type bigint".
            $duration = $previousEvent !== null
                ? (int) round($previousEvent->changed_at->diffInSeconds($changedAt))
                : null;

            $event = new DealStageEvent([
                'deal_id' => $locked->getKey(),
                'from_stage_id' => $locked->stage_id,
                'to_stage_id' => $to->getKey(),
                'changed_at' => $changedAt,
                'duration_in_previous_stage_seconds' => $duration,
            ]);
            $event->changed_by = $by->getKey();
            $event->save();

            $locked->stage_id = $to->getKey();
            $locked->status = match (true) {
                $to->is_won => Deal::STATUS_WON,
                $to->is_lost => Deal::STATUS_LOST,
                default => Deal::STATUS_OPEN,
            };
            // „Golit la redeschidere" (§9.2): un deal mutat DEPARTE de o etapă Lost nu
            // mai poartă motivul vechi.
            $locked->lost_reason = $to->is_lost ? $lostReason : null;
            $locked->save();

            return $locked->fresh(['account', 'stage', 'owner']);
        });
    }
}
