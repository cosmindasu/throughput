<?php

namespace App\Http\Controllers\Web\Deals;

use App\Actions\Deals\MoveDealStageAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Deals\UpdateDealStageRequest;
use App\Models\Deal;
use App\Models\Stage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * `PATCH /deals/{deal}/stage` — drag & drop ȘI meniul „Move to stage…" (FR-DEAL-01)
 * lovesc AMBELE această rută: alternativa de tastatură nu e un flux paralel, e alt
 * declanșator pentru același request.
 */
class DealStageController extends Controller
{
    public function move(UpdateDealStageRequest $request, Deal $deal, MoveDealStageAction $action): RedirectResponse
    {
        Gate::authorize('moveStage', $deal);

        // `Stage::findOrFail()`, nu `Rule::exists()`: respectă global scope + RLS, deci
        // un ULID dintr-un alt tenant dă 404 — nu 422 („nu există" corect, nu „există,
        // dar invalid").
        $toStage = Stage::query()->findOrFail($request->validated('to_stage_id'));

        $action->execute($deal, $toStage, $request->user(), $request->validated('lost_reason'));

        return back();
    }
}
