<?php

namespace App\Http\Controllers\Web\Settings;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\ApiTokenResource;
use App\Models\ApiToken;
use App\Support\DemoMode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings → API tokens (specs.md §18.1, FR-API-01/02, US-API-01).
 *
 * Singura parte WEB a acestui lot. API-ul public în sine nu are segment de workspace în
 * cale (§18.2) și trăiește în `routes/api.php`; ecranul de administrare e, dimpotrivă, o
 * pagină obișnuită a workspace-ului curent, deci moștenește tot grupul
 * `auth → session.context → workspace`.
 *
 * Jetonul în clar există EXACT o dată, în răspunsul lui `store()`, pus în flash-ul de
 * sesiune (`with()`), nu într-un prop persistent: la prima navigare ulterioară dispare
 * singur, fără nicio „ștergere" de gestionat — US-API-01, „nu mai e recuperabilă din UI
 * ulterior". În bază nu există: `api_tokens` păstrează doar `token_hash`.
 */
final class ApiTokenController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', ApiToken::class);

        $tokens = ApiToken::query()
            ->with('user:id,name')
            // `.ai/rules/tenancy.md` — `created_at` are precizie 0; ULID-ul e tiebreaker-ul.
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        return Inertia::render('Settings/ApiTokens/Index', [
            'tokens' => ApiTokenResource::collection($tokens),
            // Jetonul în clar, EXACT o dată (US-API-01). Vine din flash-ul pus de
            // `store()`, ca prop de pagină și nu prin `flash` din
            // `HandleInertiaRequests::share()`: acela duce doar `success`/`error`/`notice`,
            // iar un secret n-are ce căuta în canalul mesajelor de stare oricum. La prima
            // navigare ulterioară dispare singur, fără nicio curățare de gestionat.
            'plainTextToken' => $request->session()->get('plainTextToken'),
            'abilities' => collect(ApiToken::abilityCatalog())
                ->map(fn (string $description, string $ability) => [
                    'value' => $ability,
                    'description' => $description,
                ])
                ->values(),
            'can' => [
                'create' => $request->user()->can('create', ApiToken::class),
                // Revocarea se verifică per rând în `destroy()`; pe ecran e o singură
                // permisiune, fiindcă `ApiTokenPolicy::revoke()` nu îngustează pe rând
                // (un jeton e al workspace-ului, nu al celui care l-a emis).
                'revoke' => $request->user()->can('api_tokens.revoke'),
                // §22.2 — acțiunea distinctă „revocare în masă". Aceeași permisiune ca
                // `revoke` de mai sus, ȘI oprită în DEMO_MODE (`DemoMode::allows()`):
                // afordanța dispare din interfață, la fel ca `members.canDeactivate`
                // (`MembersController`) — al doilea strat e `EnsureDemoModeGuardrails`,
                // global, pe numele rutei.
                'revokeAll' => $request->user()->can('revokeAll', ApiToken::class)
                    && DemoMode::allows('api-tokens.revoke-all'),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', ApiToken::class);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'abilities' => ['required', 'array', 'min:1'],
            'abilities.*' => ['string', Rule::in(ApiToken::allowedAbilities())],
            // Expirarea e opțională (coloana e nullabilă din Faza 1). Plafonul de un an
            // ține jetoanele demo-ului public din a deveni permanente din neatenție.
            'expires_at' => ['nullable', 'date', 'after:now', 'before:'.now()->addYear()->toDateTimeString()],
        ], [
            'abilities.required' => 'Pick at least one scope — a token without scopes could not do anything.',
            'abilities.*.in' => 'Unknown scope.',
        ]);

        [, $plainTextToken] = ApiToken::issue(
            issuer: $request->user(),
            name: $validated['name'],
            abilities: array_values(array_unique($validated['abilities'])),
            expiresAt: ($validated['expires_at'] ?? null) !== null
                ? Carbon::parse($validated['expires_at'])
                : null,
        );

        return redirect()
            ->route('settings.api-tokens.index')
            ->with('success', __('flash.api_tokens.created'))
            ->with('plainTextToken', $plainTextToken);
    }

    public function destroy(Request $request, string $apiToken): RedirectResponse
    {
        $token = ApiToken::query()->find($apiToken);

        // 404, nu 403, pe un jeton al altui workspace — aceeași regulă ca peste tot
        // (ResolveWorkspace, §18.5): nu confirmăm nici măcar existența.
        abort_if($token === null, 404);

        $this->authorize('revoke', $token);

        if ($token->isRevoked()) {
            return redirect()
                ->route('settings.api-tokens.index')
                ->with('success', __('flash.api_tokens.already_revoked'));
        }

        $token->revoke();

        return redirect()
            ->route('settings.api-tokens.index')
            ->with('success', __('flash.api_tokens.revoked'));
    }

    /**
     * §22.2, „Revocarea în masă a tuturor jetoanelor API" — decizia proprietarului
     * (2026-09-22). `EnsureDemoModeGuardrails` (global) oprește ruta cât `DEMO_MODE=true`,
     * pe numele ei (`settings.api-tokens.destroy-all`, în
     * `App\Support\DemoMode::GUARDED_ACTIONS`); autorizarea de aici e al doilea strat,
     * independent de DEMO_MODE.
     */
    public function destroyAll(): RedirectResponse
    {
        $this->authorize('revokeAll', ApiToken::class);

        $revoked = ApiToken::revokeAllUsable();

        return redirect()
            ->route('settings.api-tokens.index')
            ->with('success', $revoked > 0
                ? trans_choice('flash.api_tokens.revoked_all', $revoked, ['count' => $revoked])
                : __('flash.api_tokens.revoked_all_none'));
    }
}
