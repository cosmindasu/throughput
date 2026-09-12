<?php

namespace App\Http\Middleware;

use App\Models\Membership;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rezolvarea tenantului din segmentul de cale (ADR-002), ÎN tranzacția deja deschisă de
 * SetSessionContext — deci fără tranzacție imbricată și fără savepoint.
 */
class ResolveWorkspace
{
    public function handle(Request $request, Closure $next): Response
    {
        $userId = $request->user()?->getAuthIdentifier();

        abort_if($userId === null, 403);

        // Membership-urile proprii sunt vizibile grație lui `app.user_id`, setat de
        // SetSessionContext înainte ca vreun tenant să fie cunoscut (ADR-014, pct. 2).
        // Aceeași colecție alimentează și comutatorul de workspace din interfață
        // (FR-TEN-01), deci se rezolvă o singură dată pe cerere.
        $memberships = Membership::forCurrentUserAcrossTenants($userId);
        app()->scoped('memberships', fn () => $memberships);

        $slug = (string) $request->route('workspace');

        $membership = $memberships->first(
            fn (Membership $membership) => $membership->tenant?->slug === $slug
        );

        // 404, nu 403: un workspace în care nu ești membru nu trebuie nici măcar
        // confirmat că există — enumerarea e jumătate din BOLA (§18.5, §20.2).
        abort_if($membership === null, 404);

        /** @var Tenant $tenant */
        $tenant = $membership->tenant;

        // `scoped()`, nu `singleton()`: aruncat la finalul cererii/jobului, ca un worker
        // să nu moștenească tenantul cererii anterioare (§6.1).
        //
        // Și `scoped()`, nu `scopedIf()` (cum scria planul): „leagă doar dacă nu e deja
        // legat" înseamnă că, într-un proces care servește două cereri fără reboot —
        // Octane, sau două cereri în același test — comutarea workspace-ului păstrează
        // TĂCUT tenantul precedent în props, deși contextul de bază s-a schimbat corect.
        // Adică exact ecranul „am comutat și văd tot Marlin". Reprodus în DashboardTest.
        app()->scoped('tenant', fn () => $tenant);

        TenantContext::setTenant($tenant->getKey());

        // Pasul din documentația spatie/laravel-permission ușor de omis: fără el, orice
        // verificare de rol interoghează tenantul greșit — sau niciunul.
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());

        // Toate `route()`-urile și linkurile Inertia propagă segmentul automat, fără să
        // fie repetat manual în fiecare componentă React (ADR-002).
        URL::defaults(['workspace' => $tenant->slug]);

        // Segmentul și-a făcut treaba, deci iese din parametrii rutei. Dispatcher-ul Laravel
        // îi pasează metodei de controller POZIȚIONAL (`...array_values()`), așa că
        // `show(Account $account)` ar primi slug-ul workspace-ului în locul contului —
        // TypeError pe fiecare rută cu parametru. `URL::defaults` de mai sus acoperă în
        // continuare generarea de linkuri.
        $request->route()->forgetParameter('workspace');

        return $next($request);
    }
}
