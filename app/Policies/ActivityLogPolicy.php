<?php

namespace App\Policies;

use App\Models\User;

/**
 * Matricea §7.4, rândul „Jurnal de activitate" — ecranul TENANT-WIDE
 * (`App\Http\Controllers\Web\ActivityLogController::index()`, FR-AUD-03), filtrabil pe tip
 * de acțiune, utilizator și interval: Owner/Manager văd tot (`activity_log.view`), Agentul
 * doar acțiunile proprii (`activity_log.view_own`, îngustat la nivel de interogare în
 * controller — la fel ca restul aplicației, un Policy răspunde la „poate omul ăsta accesa
 * ECRANUL", nu la „ce rânduri anume"), Viewer-ul deloc.
 *
 * NU acoperă tab-ul „History" al unei entități (FR-AUD-02, `ActivityLogController::
 * forEntity()`) — acela e gated de Policy-ul ENTITĂȚII înseși (`AccountPolicy::view()`
 * etc.): US-AUD-01 cere ca oricine poate vedea o variantă să-i vadă istoricul de preț,
 * indiferent de rol — Gherkin-ul din §17.3 restrânge explicit doar ecranul de mai sus, nu
 * tab-urile de entitate. A amesteca cele două ar fi însemnat ca un Agent să nu-și mai poată
 * vedea propriul istoric de preț pe o variantă pe care ARE voie s-o vadă.
 */
class ActivityLogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('activity_log.view') || $user->can('activity_log.view_own');
    }
}
