<?php

namespace App\Policies;

use App\Models\Pipeline;
use App\Models\User;

/**
 * Matricea §7.4, rândul „Configurare pipeline / etape": Owner/Manager au CRUD (`pipelines.manage`),
 * Viewer are doar R (`pipelines.view`), Agentul nu are nimic.
 *
 * Asimetria Agent/Viewer e deja semnalată, nu corectată în tăcere, în `Permissions::forRoles()`:
 * Agentul lucrează pipeline-ul din kanban (`deals.view`), nu din acest ecran de configurare.
 */
class PipelinePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('pipelines.view');
    }

    public function view(User $user, Pipeline $pipeline): bool
    {
        return $user->can('pipelines.view');
    }

    public function manage(User $user, Pipeline $pipeline): bool
    {
        return $user->can('pipelines.manage');
    }
}
