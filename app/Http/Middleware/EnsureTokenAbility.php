<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * FR-API-01 — „Un request cu un token căruia îi lipsește scope-ul cerut de endpoint
 * primește `403` cu mesaj explicit despre scope-ul lipsă."
 *
 * Mesajul e cel din Gherkin-ul US-API-01, literal: „Missing required scope: orders:write".
 *
 * `403`, nu `404`: spre deosebire de BOLA (§18.5, unde un `403` ar confirma existența unei
 * resurse a altui tenant), aici nu se divulgă nimic — apelantul își cunoaște deja propriile
 * scopuri, iar un `404` l-ar trimite să caute o rută care există.
 *
 * Se aplică DUPĂ `ResolveTenantFromApiToken`, care pune scopurile în atributele cererii.
 * Chemat fără el, refuză: o rută care primește scopuri dintr-un jeton neautentificat n-ar
 * fi o gardă, ar fi o decorațiune.
 */
class EnsureTokenAbility
{
    public function handle(Request $request, Closure $next, string $ability): Response
    {
        /** @var list<string> $abilities */
        $abilities = $request->attributes->get(ResolveTenantFromApiToken::ABILITIES_ATTRIBUTE, []);

        if (! in_array($ability, $abilities, true)) {
            return response()->json([
                'message' => "Missing required scope: {$ability}",
                'requiredScope' => $ability,
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
