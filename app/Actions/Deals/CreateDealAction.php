<?php

namespace App\Actions\Deals;

use App\Models\Deal;
use App\Models\DealStageEvent;
use App\Models\Pipeline;
use App\Models\Stage;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * US-DEAL-01 — un deal nou pornește pe prima etapă (după `position`) a pipeline-ului
 * implicit al tenantului, cu owner = creatorul (dacă nu are voie să aleagă altul) și cu
 * PRIMUL `deal_stage_events` inserat aici, în aceeași tranzacție — nu duplicat în
 * controller. `from_stage_id = null` marchează evenimentul de creare (§9.2).
 *
 * O clasă = o operație (plan §1.1, `app/Actions/`): `DealController::store()` doar
 * validează și autorizează, logica de business (care pipeline, care etapă, ce eveniment)
 * stă aici, testabilă fără HTTP.
 */
final class CreateDealAction
{
    /**
     * @param  array{account_id: string, primary_contact_id?: ?string, title: string, value?: ?float, currency?: ?string, expected_close_date?: ?string, owner_user_id?: ?string}  $data
     */
    public function execute(array $data, User $by): Deal
    {
        return DB::transaction(function () use ($data, $by): Deal {
            // MVP: „un singur pipeline implicit per tenant" (§9.2) — pipeline-uri
            // multiple sunt Faza 2+ (FR-DEAL-04). Fallback pe cel mai vechi dacă
            // niciunul nu e marcat implicit (nu ar trebui să se întâmple după seed,
            // dar un tenant de test poate crea un singur pipeline fără steag).
            $pipeline = Pipeline::query()->where('is_default', true)->first()
                ?? Pipeline::query()->oldest('created_at')->firstOrFail();

            $firstStage = Stage::query()
                ->where('pipeline_id', $pipeline->getKey())
                ->orderBy('position')
                ->firstOrFail();

            $deal = new Deal([
                'account_id' => $data['account_id'],
                'primary_contact_id' => $data['primary_contact_id'] ?? null,
                'pipeline_id' => $pipeline->getKey(),
                'stage_id' => $firstStage->getKey(),
                'owner_user_id' => $data['owner_user_id'] ?? $by->getKey(),
                'title' => $data['title'],
                'value' => $data['value'] ?? null,
                'currency' => $data['currency'] ?? 'USD',
                'expected_close_date' => $data['expected_close_date'] ?? null,
                'status' => Deal::STATUS_OPEN,
            ]);
            // `created_by` nu e în #[Fillable] al Deal (ca și pe Account) — se
            // asignează direct, nu prin mass-assignment.
            $deal->created_by = $by->getKey();
            $deal->save();

            $event = new DealStageEvent([
                'deal_id' => $deal->getKey(),
                'from_stage_id' => null,
                'to_stage_id' => $firstStage->getKey(),
                'changed_at' => now(),
                'duration_in_previous_stage_seconds' => null,
            ]);
            // `changed_by` nu e fillable pe DealStageEvent (§9.1 — append-only,
            // fără o „definiție" generică independentă de context).
            $event->changed_by = $by->getKey();
            $event->save();

            return $deal->fresh(['account', 'stage', 'owner']);
        });
    }
}
