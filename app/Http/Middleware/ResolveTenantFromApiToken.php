<?php

namespace App\Http\Middleware;

use App\Models\ApiToken;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;

/**
 * Poarta API-ului public (specs.md §18.1/§18.2, ADR-008, ADR-014).
 *
 * Echivalentul lui `SetSessionContext` + `ResolveWorkspace` pentru o cerere cu jeton,
 * într-un singur pas fiindcă aici nu există două surse: **tenantul vine din jeton, nu din
 * URL** (§18.2). Calea API-ului nu are și nu va avea segment de workspace.
 *
 * Ordinea internă e cea din ADR-014, pct. 3, și contează la fel de mult:
 *   1. jetonul se autentifică pe `personal_access_tokens` (tabelă FĂRĂ RLS, identitate
 *      globală ca `users`) — `hash_equals`, prin `findToken()`;
 *   2. tenantul se citește din `abilities` (vezi docblock-ul `App\Models\ApiToken` pentru
 *      de ce acolo și de ce NU e un claim al clientului);
 *   3. se deschide tranzacția și se setează `app.user_id` + `app.tenant_id`;
 *   4. abia acum se citește rândul APLICATIV `api_tokens`, SUB RLS — ceea ce face din
 *      stratul 2 verificarea stratului 1: un `tenant:` falsificat nu găsește nimic.
 *
 * ## `SubstituteBindings` rulează ÎNAINTEA acestui middleware
 *
 * Grupul `api` al framework-ului conține `SubstituteBindings`, iar lista de prioritate
 * (`bootstrap/app.php`) ridică `SetSessionContext`/`ResolveWorkspace` deasupra lui — nu și
 * acest middleware, care e al rutelor API. Consecința, aceeași capcană descrisă în
 * `.ai/rules/tenancy.md`: un parametru de rută TIPIZAT (`show(Order $order)`) s-ar rezolva
 * printr-o interogare Eloquent fără context și ar da 500, nu 404. De aceea **rutele din
 * `routes/api.php` folosesc parametri `string`**, iar fiecare controller își încarcă
 * modelul explicit — ceea ce e oricum ce cere §18.5 („verificare de ownership server-side
 * pe fiecare endpoint"), nu un compromis.
 */
class ResolveTenantFromApiToken
{
    /** Cheia sub care scopurile jetonului curent ajung la `EnsureTokenAbility`. */
    public const ABILITIES_ATTRIBUTE = 'api_token_abilities';

    /** Cheia sub care rândul aplicativ ajunge la controllere (ex. pentru jurnalizare). */
    public const TOKEN_ATTRIBUTE = 'api_token';

    public function handle(Request $request, Closure $next): Response
    {
        $bearer = $request->bearerToken();

        if ($bearer === null || $bearer === '') {
            return $this->unauthenticated('No API token was provided. Send it as `Authorization: Bearer <token>`.');
        }

        $personalAccessToken = Sanctum::personalAccessTokenModel();

        /** @var PersonalAccessToken|null $sanctumToken */
        $sanctumToken = $personalAccessToken::findToken($bearer);

        if ($sanctumToken === null) {
            return $this->unauthenticated();
        }

        /** @var list<string> $sanctumAbilities */
        $sanctumAbilities = $sanctumToken->abilities ?? [];

        $tenantId = ApiToken::tenantIdFromAbilities($sanctumAbilities);

        if ($tenantId === null || $sanctumToken->expires_at?->isPast()) {
            return $this->unauthenticated();
        }

        // `tenants` n-are RLS (identitate de organizație, nu date de business), deci se
        // poate citi înainte ca vreun context să existe — la fel ca în `ResolveWorkspace`,
        // unde tenantul se află tot înaintea lui `setTenant()`.
        $tenant = Tenant::query()->whereKey($tenantId)->first();
        $user = User::query()->whereKey($sanctumToken->tokenable_id)->first();

        if ($tenant === null || $user === null) {
            return $this->unauthenticated();
        }

        return TenantContext::openFor($user->getKey(), function () use ($request, $next, $tenant, $user, $sanctumToken, $sanctumAbilities) {
            TenantContext::setTenant($tenant->getKey());

            $apiToken = ApiToken::query()->where('token_hash', $sanctumToken->token)->first();

            // Membership-ul e verificat la FIECARE cerere, nu doar la emitere: un membru
            // dezactivat (BR-TEN-04) nu trebuie să mai poată scrie prin jetonul pe care
            // îl emisese cât era activ — altfel dezactivarea ar fi doar o blocare de UI.
            $membershipIsActive = Membership::query()
                ->where('user_id', $user->getKey())
                ->where('status', Membership::STATUS_ACTIVE)
                ->exists();

            if ($apiToken === null || ! $apiToken->isUsable() || ! $membershipIsActive) {
                return $this->unauthenticated();
            }

            $this->touchLastUsedAt($apiToken, $sanctumToken);

            // Contextul de autorizare, identic cu cel al cererii web: `Gate`, Policies și
            // `spatie/laravel-permission` citesc toate de aici. Jetonul ÎNGUSTEAZĂ ce
            // poate face utilizatorul (scopuri), nu lărgește — rolul rămâne plafonul.
            Auth::setUser($user);
            $request->setUserResolver(fn () => $user);

            // `scoped()`, exact ca în `ResolveWorkspace` și din același motiv (§6.1): un
            // proces cu viață lungă nu trebuie să moștenească tenantul cererii anterioare.
            app()->scoped('tenant', fn () => $tenant);
            app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());

            $request->attributes->set(self::ABILITIES_ATTRIBUTE, ApiToken::businessAbilities($sanctumAbilities));
            $request->attributes->set(self::TOKEN_ATTRIBUTE, $apiToken);

            return $next($request);
        });
    }

    /**
     * `last_used_at` pe amândouă rândurile, dar cel mult o dată pe minut.
     *
     * Un UPDATE la fiecare cerere ar însemna, la plafonul FR-API-05, 300 de scrieri pe
     * minut per jeton într-o tranzacție care ține toată cererea — adică exact genul de
     * scriere care blochează, pe un container cu `max_connections=30`. Granularitatea de
     * un minut e suficientă pentru ce afișează ecranul („last used"), iar comparația se
     * face pe valoarea deja încărcată, fără o interogare în plus.
     */
    private function touchLastUsedAt(ApiToken $apiToken, PersonalAccessToken $sanctumToken): void
    {
        if ($apiToken->last_used_at !== null && abs($apiToken->last_used_at->diffInSeconds(now())) < 60) {
            return;
        }

        $now = now();

        $apiToken->forceFill(['last_used_at' => $now])->save();
        $sanctumToken->forceFill(['last_used_at' => $now])->save();
    }

    private function unauthenticated(string $message = 'The API token is invalid, expired or revoked.'): Response
    {
        return response()->json(['message' => $message], Response::HTTP_UNAUTHORIZED);
    }
}
