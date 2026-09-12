<?php

namespace App\Http\Middleware;

use App\Services\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * PRIMUL middleware al grupului autenticat (ADR-014, pct. 3).
 *
 * Deschide tranzacția cererii și setează `app.user_id`, de care `memberships` are nevoie
 * ca să fie vizibil înainte să se știe workspace-ul. Dacă rulează DUPĂ ResolveWorkspace,
 * nu apare nicio eroare — doar un comutator de workspace gol. De aceea ordinea e verificată
 * de un test (MiddlewareOrderTest), nu doar de un comentariu.
 *
 * Consecință directă (ADR-013 + ADR-014, pct. 6): tranzacția stă deschisă pe toată durata
 * cererii, deci niciun apel extern nu are voie într-un controller, iar `after_commit` e
 * obligatoriu pe conexiunea de coadă.
 */
class SetSessionContext
{
    public function handle(Request $request, Closure $next): Response
    {
        return TenantContext::openFor(
            $request->user()?->getAuthIdentifier(),
            fn () => $next($request)
        );
    }
}
