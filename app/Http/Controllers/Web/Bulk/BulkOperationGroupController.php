<?php

namespace App\Http\Controllers\Web\Bulk;

use App\Http\Controllers\Controller;
use App\Http\Resources\Bulk\BulkOperationGroupResource;
use App\Models\BulkOperation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Progresul AGREGAT al unui `group_id` (BR-BULK-04, §13.2) — US-TEN-03 e singurul caz din
 * MVP: reatribuirea la dezactivarea unui membru creează trei rânduri `bulk_operations`
 * (conturi, deals, comenzi), legate prin același `group_id`. Distinct de
 * `BulkOperationController`, care lucrează pe UN singur `BulkOperation` — reutilizează
 * `BulkOperationPolicy` (`view`/`cancel`) pe FIECARE rând al grupului, nu o Policy nouă:
 * „a utilizatorului ăsta e grupul ăsta" înseamnă „a utilizatorului ăsta e FIECARE operație
 * din grup" (toate create de același actor, în aceeași cerere — vezi
 * `MembersController::deactivate()`).
 */
final class BulkOperationGroupController extends Controller
{
    public function show(Request $request, string $group): Response
    {
        return Inertia::render('Bulk/Groups/Show', [
            'group' => new BulkOperationGroupResource($this->operationsForGroup($request, $group, 'view')),
        ]);
    }

    /**
     * Simetric cu `BulkOperationController::cancel()`, pe fiecare operație a grupului — nu
     * cheamă acea metodă direct (semnătura ei cere o instanță deja rezolvată de rută), dar
     * folosește exact aceeași regulă: `$batch->cancel()` dacă `batch_id` există deja,
     * altfel un `UPDATE` atomic condiționat pe stare, pentru fereastra scurtă dintre
     * `running` și scrierea `batch_id` de către planificator.
     */
    public function cancel(Request $request, string $group): RedirectResponse
    {
        // P2-003 (review general) — `cancel`, NU `view`: `BulkOperationPolicy::cancel()`
        // are o condiție ÎN PLUS față de `view()` (`BulkChunkActions::isRegistered()`,
        // vezi docblock-ul acelei metode). Verificarea de mai jos folosea `view` pentru
        // AMBELE acțiuni, deci anularea moștenea doar regula mai slabă — irelevant azi,
        // cât timp `bulk_operations` din grup sunt mereu `reassign_owner` (înregistrat),
        // dar greșit ca principiu: o acțiune de scriere trebuie verificată cu abilitatea
        // ei proprie, nu cu una de citire.
        foreach ($this->operationsForGroup($request, $group, 'cancel') as $operation) {
            $this->cancelOne($operation);
        }

        return back()->with('success', 'Cancelling every operation in this group — rows already in progress will finish, the rest stop.');
    }

    /**
     * @return Collection<int, BulkOperation>
     */
    private function operationsForGroup(Request $request, string $group, string $ability): Collection
    {
        abort_unless(Str::isUlid($group), 404);

        $operations = BulkOperation::query()->where('group_id', $group)->get();

        abort_if($operations->isEmpty(), 404);

        abort_unless(
            $operations->every(fn (BulkOperation $op) => Gate::forUser($request->user())->allows($ability, $op)),
            403,
        );

        return $operations;
    }

    private function cancelOne(BulkOperation $operation): void
    {
        $fresh = $operation->fresh();

        if ($fresh === null) {
            return;
        }

        if ($fresh->batch_id !== null) {
            Bus::findBatch($fresh->batch_id)?->cancel();

            return;
        }

        $cancelled = BulkOperation::query()
            ->whereKey($fresh->getKey())
            ->whereNull('batch_id')
            ->whereIn('status', [BulkOperation::STATUS_PENDING, BulkOperation::STATUS_RUNNING])
            ->update(['status' => BulkOperation::STATUS_CANCELLED]);

        if ($cancelled > 0) {
            return;
        }

        // Am pierdut cursa (ca în `BulkOperationController::cancel()`): planificatorul a
        // scris `batch_id` chiar între citire și `UPDATE`. Recitim și anulăm batch-ul
        // proaspăt creat, dacă a apucat să apară.
        $batchId = $fresh->fresh()?->batch_id;

        if ($batchId !== null) {
            Bus::findBatch($batchId)?->cancel();
        }
    }
}
