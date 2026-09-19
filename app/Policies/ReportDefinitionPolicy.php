<?php

namespace App\Policies;

use App\Models\ReportDefinition;
use App\Models\User;
use App\Support\Permissions;
use App\Support\Reports\ReportRecipients;

/**
 * Matricea §7.4, rândul „Rapoarte programate": Owner/Manager CRUD (`reports.manage` la
 * scriere, `reports.view` la citire), Agent „R (doar cele unde e destinatar)" — o ÎNGUSTARE
 * ABAC peste `reports.view` (§7.5), nu o permisiune separată — Viewer refuzat pe tot
 * (n-are nici `reports.view`, nici `reports.manage`).
 *
 * NUMITĂ `ReportDefinitionPolicy`, nu `ReportPolicy` — deliberat, deși task-ul lotului
 * numea clasa „ReportPolicy": Laravel rezolvă Policy-ul unui model prin auto-discovery
 * pe convenția `{Model}Policy` (verificat — niciun `Gate::policy()` explicit nicăieri în
 * `app/Providers`, care oricum e interzis acestui lot), iar modelul e `ReportDefinition`.
 * O clasă numită `ReportPolicy` nu s-ar fi legat NICIODATĂ automat de
 * `$user->can('view', $reportDefinition)` — semnalat aici, corectat, nu doar notat.
 *
 * „Run now" (US-REP-02) cere `reports.manage`, deși e declanșat dintr-o pagină pe care
 * Agentul o poate VEDEA (dacă e destinatar): a rula un raport consumă resurse de coadă și
 * creează un `report_runs` nou — o acțiune de SCRIERE, nu de citire. Matricea dă Agentului
 * doar „R", deci „Run now" rămâne Owner/Manager, la fel ca US-REP-02 ("Ca Owner...").
 */
class ReportDefinitionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('reports.view');
    }

    public function view(User $user, ReportDefinition $report): bool
    {
        if (! $user->can('reports.view')) {
            return false;
        }

        if (Permissions::restrictedToOwnRecords($user)) {
            return ReportRecipients::isRecipient($report, $user);
        }

        return true;
    }

    public function create(User $user): bool
    {
        return $user->can('reports.manage');
    }

    public function update(User $user, ReportDefinition $report): bool
    {
        return $user->can('reports.manage');
    }

    public function delete(User $user, ReportDefinition $report): bool
    {
        return $user->can('reports.manage');
    }

    public function runNow(User $user, ReportDefinition $report): bool
    {
        return $user->can('reports.manage');
    }

    /**
     * Descărcarea unui fișier de rulare urmează dreptul de CITIRE al raportului-părinte
     * (FR-REP-01 — istoricul, cu descărcare, apare pe aceeași pagină pe care Agentul o
     * poate vedea dacă e destinatar).
     */
    public function download(User $user, ReportDefinition $report): bool
    {
        return $this->view($user, $report);
    }
}
