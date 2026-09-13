<?php

namespace App\Policies;

use App\Models\Stage;
use App\Models\User;

/**
 * Etapele individuale ale pipeline-ului (matricea §7.4, rândul „Configurare pipeline / etape").
 *
 * O singură permisiune (`pipelines.manage`) guvernează create/update/delete/reorder — nu e o
 * regulă ABAC de tip „doar etapele proprii" (nu există ownership pe etape, spre deosebire de
 * Account/Deal, §7.5). BR-DEAL-01 (etapă cu deals nu se șterge) NU e aici, ci în
 * `Stage::deletionBlockedReason()`: e o stare a etapei, nu un drept al utilizatorului — la fel
 * ca la `AccountPolicy`.
 */
class StagePolicy
{
    public function create(User $user): bool
    {
        return $user->can('pipelines.manage');
    }

    public function update(User $user, Stage $stage): bool
    {
        return $user->can('pipelines.manage');
    }

    public function delete(User $user, Stage $stage): bool
    {
        return $user->can('pipelines.manage');
    }

    /**
     * Reordonarea nu are un model unic ca subiect (operează pe toată lista), deci nu e o
     * metodă cu semnătura implicită `(User, Stage)` — verificată explicit cu
     * `Gate::authorize('reorder', Stage::class)`.
     */
    public function reorder(User $user): bool
    {
        return $user->can('pipelines.manage');
    }
}
