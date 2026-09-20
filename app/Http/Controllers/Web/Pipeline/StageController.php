<?php

namespace App\Http\Controllers\Web\Pipeline;

use App\Actions\Pipeline\ReorderStagesAction;
use App\Actions\Pipeline\SaveStageAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pipeline\ReorderStagesRequest;
use App\Http\Requests\Pipeline\StoreStageRequest;
use App\Http\Requests\Pipeline\UpdateStageRequest;
use App\Models\Pipeline;
use App\Models\Stage;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * CRUD + reordonare a etapelor unui pipeline (FR-DEAL-02). Restrâns la Owner/Manager
 * (`pipelines.manage`) prin `StagePolicy` — Viewer nu ajunge la niciuna dintre acțiuni,
 * Agentul nici măcar la ecranul de listare (`PipelineController::index`).
 *
 * Fiecare acțiune redirecționează înapoi (`back()`), fără pagină dedicată: ecranul e o
 * singură listă de configurare, nu un flux multi-pagină.
 */
class StageController extends Controller
{
    public function __construct(
        private readonly SaveStageAction $saveStage,
        private readonly ReorderStagesAction $reorderStages,
    ) {}

    public function store(StoreStageRequest $request): RedirectResponse
    {
        $this->saveStage->execute(Pipeline::resolveDefault(), $request->stageData());

        return back()->with('success', __('flash.stages.created'));
    }

    public function update(UpdateStageRequest $request, Stage $stage): RedirectResponse
    {
        $this->saveStage->execute($stage->pipeline, $request->stageData(), $stage);

        return back()->with('success', __('flash.stages.updated'));
    }

    public function destroy(Stage $stage): RedirectResponse
    {
        Gate::authorize('delete', $stage);

        // Verificare proactivă (BR-DEAL-01, §9.1): mesaj explicit, nu un ecran gol sau un 500.
        if ($reason = $stage->deletionBlockedReason()) {
            return back()->with('error', $reason);
        }

        try {
            // Savepoint propriu: un DELETE respins de Postgres abandonează tranzacția în care
            // rulează, iar aici aceea e tranzacția întregii cereri (ADR-014). Fără savepoint,
            // orice interogare de după `catch` ar pica, iar commit-ul cererii ar deveni ROLLBACK.
            DB::transaction(fn () => $stage->delete());
        } catch (QueryException $e) {
            // Plasă de siguranță pentru cursa creare-deal/ștergere-etapă între verificarea de
            // mai sus și acest DELETE: `deals.stage_id` și FK-urile din `deal_stage_events` sunt
            // FĂRĂ cascadă, deci Postgres ar respinge cu 23503 — un refuz explicit rămâne mai
            // bun decât un 500 necaptat (BR-DEAL-01, cerut explicit „refuz cu mesaj, nu 500").
            if ($e->getCode() !== '23503') {
                throw $e;
            }

            return back()->with('error', __('flash.stages.deletion_blocked_fk'));
        }

        return back()->with('success', __('flash.stages.deleted'));
    }

    public function reorder(ReorderStagesRequest $request): RedirectResponse
    {
        $this->reorderStages->execute(Pipeline::resolveDefault(), $request->orderedStageIds());

        return back()->with('success', __('flash.stages.reordered'));
    }
}
