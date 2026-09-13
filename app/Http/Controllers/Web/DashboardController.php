<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Resources\ActivityEntryResource;
use App\Models\ActivityLog;
use App\Models\Deal;
use App\Models\InventoryLevel;
use App\Models\Invoice;
use App\Models\Membership;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * FR-DEMO-01 — dashboard-ul de start al unui workspace: 4 KPI tiles + feed de
 * activitate. Interogări simple de agregare, direct pe tabelele deja populate de seed
 * (§7.8) — scopate automat de `TenantScope` (plan §7.2): NICIUN `where tenant_id`
 * manual aici, RLS + global scope se ocupă de izolare.
 */
class DashboardController extends Controller
{
    /**
     * `/dashboard` fără workspace în cale (ADR-002) — redirect către primul workspace
     * al utilizatorului, ca în comutator (ordonat după numele tenantului,
     * `Membership::forCurrentUserAcrossTenants`).
     */
    public function redirectToDefaultWorkspace(Request $request): RedirectResponse
    {
        $userId = $request->user()->getAuthIdentifier();

        $membership = Membership::forCurrentUserAcrossTenants($userId)->first();

        // Un utilizator autentificat fără niciun membership activ nu are unde ajunge —
        // situație de configurare, nu de rutare greșită.
        abort_if($membership === null, 403, 'This account is not a member of any workspace.');

        return redirect()->route('workspace.dashboard', ['workspace' => $membership->tenant->slug]);
    }

    public function show(Request $request): Response
    {
        return Inertia::render('Dashboard', [
            'kpis' => [
                'openPipelineValue' => (float) Deal::query()
                    ->where('status', Deal::STATUS_OPEN)
                    ->sum('value'),

                'ordersThisMonth' => (int) Order::query()
                    ->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()])
                    ->count(),

                'overdueInvoices' => [
                    'count' => (int) Invoice::query()->where('status', Invoice::STATUS_OVERDUE)->count(),
                    // `balance_due`, nu `total`: e suma încă restantă, nu valoarea
                    // inițială a facturii — semnificația literală a „restante".
                    'amount' => (float) Invoice::query()->where('status', Invoice::STATUS_OVERDUE)->sum('balance_due'),
                ],

                'lowStockAlerts' => (int) InventoryLevel::query()
                    ->whereRaw('(on_hand - reserved) <= ?', [5])
                    ->count(),
            ],

            'activity' => $this->recentActivity($request->user()),
        ]);
    }

    /**
     * Feed-ul e o citire a jurnalului de activitate, deci urmează rândul lui din matricea §7.4:
     * Owner și Manager văd tot tenantul (`activity_log.view`), Agentul doar acțiunile proprii
     * (`activity_log.view_own`), Viewer-ul nimic. Cine n-are acces primește `null`, nu o listă
     * goală: „No recent activity yet" ar afirma ceva fals despre workspace.
     */
    private function recentActivity(User $user): ?AnonymousResourceCollection
    {
        $seesWholeTenant = $user->can('activity_log.view');

        if (! $seesWholeTenant && ! $user->can('activity_log.view_own')) {
            return null;
        }

        return ActivityEntryResource::collection(
            ActivityLog::query()
                ->when(! $seesWholeTenant, fn (Builder $query) => $query->where('user_id', $user->getKey()))
                ->with('user')
                ->latest('created_at')
                ->limit(10)
                ->get()
        );
    }
}
