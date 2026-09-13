<?php

namespace App\Http\Controllers\Web\Pipeline;

use App\Http\Controllers\Controller;
use App\Http\Resources\StageResource;
use App\Models\Pipeline;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Ecranul de configurare pipeline/etape (Pachetul D, FR-DEAL-02). MVP: un singur pipeline
 * implicit per tenant (specs.md §9.2) — de aceea nicio rută de aici nu poartă `{pipeline}` în
 * cale, spre deosebire de kanban-ul din pachetul de Deals, care citește etapele acestui
 * pipeline, dar nu le administrează.
 */
class PipelineController extends Controller
{
    public function index(Request $request): Response
    {
        // `Gate::authorize`, nu `abort_unless($request->user()->can(...))`: Agentul nu are
        // `pipelines.view` deloc (asimetrie semnalată în `Permissions::forRoles()`) — trebuie
        // 403, nu un ecran gol care ar sugera „nu există etape".
        Gate::authorize('viewAny', Pipeline::class);

        $pipeline = Pipeline::resolveDefault();

        $stages = $pipeline->stages()
            ->withCount('deals')
            ->orderBy('position')
            ->get();

        return Inertia::render('Pipeline/Index', [
            'pipelineName' => $pipeline->name,
            'stages' => StageResource::collection($stages),

            // Prop PER PAGINĂ (plan §1.2 regula 2), NU sub `navigation` — vezi comentariul
            // din HandleInertiaRequests despre combinarea superficială a props-urilor Inertia.
            'can' => [
                'manage' => $request->user()->can('pipelines.manage'),
            ],
        ]);
    }
}
