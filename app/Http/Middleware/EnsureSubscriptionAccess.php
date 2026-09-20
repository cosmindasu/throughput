<?php

namespace App\Http\Middleware;

use App\Enums\SubscriptionAccessLevel;
use App\Support\Billing\SubscriptionAccessPolicy;
use App\Support\Members\ActiveOwners;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * specs.md §12.2 (tabelul de degradare pe 3 trepte) + plan §11 — „Aplicată ca middleware
 * pe rutele de SCRIERE, niciodată pe citire/export". Înregistrat pe TOT grupul cu
 * workspace (`routes/web.php`, imediat după `workspace`), nu pe fiecare rută de scriere
 * individual: modulele Faza 2-4 (accounts/deals/orders/...) nu sunt fișierele acestui
 * lot, iar o listă de rute de mână s-ar dezactualiza la fiecare modul nou. Efectul se
 * manifestă doar pe scriere fiindcă middleware-ul însuși decide după METODA cererii, nu
 * după calea ei — vezi motivarea per ramură mai jos.
 *
 * NIVELUL SE CITEȘTE dintr-un singur loc (`SubscriptionAccessPolicy`, BR-BILL-04).
 *
 * - `Full` (`active`/`past_due`) — trece, necondiționat.
 * - `ReadOnly` (`unpaid`) — orice cerere cu metodă „safe" (GET/HEAD/OPTIONS — citire)
 *   trece; restul (POST/PUT/PATCH/DELETE — scriere) sunt refuzate, INDIFERENT de rol,
 *   inclusiv Owner (US-BILL-04). Ruta de billing rămâne mereu accesibilă — altfel un
 *   Owner blocat n-ar mai putea nici măcar actualiza cardul care l-ar debloca.
 * - `Blocked` (`canceled`) — nimic în afara paginii de billing/reactivare, INDIFERENT de
 *   metodă: „vizualizare" nu mai e permisă nicăieri altundeva (US-BILL-04, Gherkin:
 *   „singura pagină accesibilă e ecranul de billing/reactivare").
 *
 * Exportul de date GDPR (§20.5) rămâne explicit permis în AMBELE stări restrictive
 * („declanșare export... permise"). Valul 1 n-avea cum să verifice numele rutelor — modulul
 * se construia în paralel — și a lăsat aici o presupunere documentată, `data_exports.*`,
 * după convenția din `App\Support\Permissions::catalog()`. Rutele reale, scrise la
 * integrarea valului 2, se numesc `settings.data-export.*`: prefixul de mai jos e cel
 * corectat. Fără corecție, un tenant `unpaid` primea 403 la declanșarea exportului, adică
 * exact inversul a ce cere §12.2 — și niciun test nu l-ar fi prins, fiindcă prefixul
 * greșit nu se potrivea cu nicio rută existentă.
 */
class EnsureSubscriptionAccess
{
    /**
     * @var list<string>
     */
    private const EXPORT_EXEMPT_ROUTE_PREFIXES = [
        // §20.5, FR-GDPR-01 — „declanșare export" rămâne permisă chiar în `unpaid`/
        // `canceled`. Numele REAL al rutelor, verificat: `routes/web/data-export.php`.
        'settings.data-export.',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (! app()->bound('tenant')) {
            return $next($request);
        }

        $level = SubscriptionAccessPolicy::levelFor(app('tenant'));

        if ($level === SubscriptionAccessLevel::Full) {
            return $next($request);
        }

        $routeName = (string) $request->route()?->getName();

        // Ruta de billing (a acestui lot) rămâne mereu accesibilă, în AMBELE stări
        // restrictive — e singura cale prin care Owner-ul iese din blocaj.
        if (str_starts_with($routeName, 'settings.billing.')) {
            return $next($request);
        }

        if ($this->isExportExempt($routeName)) {
            return $next($request);
        }

        if ($level === SubscriptionAccessLevel::Blocked) {
            return $this->respondBlocked($request);
        }

        // ReadOnly (`unpaid`) — citirile (metodă „safe") trec neschimbat.
        if ($request->isMethodSafe()) {
            return $next($request);
        }

        return $this->respondReadOnly($request);
    }

    private function isExportExempt(string $routeName): bool
    {
        foreach (self::EXPORT_EXEMPT_ROUTE_PREFIXES as $prefix) {
            if (str_starts_with($routeName, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * P3 securitate (review-ul lotului, pct. 6) — reprodus: un Manager (sau orice rol fără
     * `billing.view`) redirectat spre `settings.billing.index` primea 403 de la
     * `BillingController::authorizeBillingView()` — capăt de drum, fără nicio pagină
     * funcțională în workspace în afara logout-ului, fără nicio explicație. Redirectul de
     * mai jos rămâne calea pentru Owner (singurul care poate acționa); cine NU are
     * `billing.view` primește direct un ecran dedicat, randat AICI, nu o rută pe care oricum
     * n-are dreptul s-o vadă.
     */
    private function respondBlocked(Request $request): Response
    {
        if ($request->user()?->can('billing.view')) {
            // US-BILL-04, Gherkin — „singura pagină accesibilă e ecranul de billing" — un
            // redirect, nu un 403: ecranul de destinație EXPLICĂ situația și oferă
            // „Reactivate", un 403 brut n-ar spune ce trebuie făcut.
            return redirect()->route('settings.billing.index');
        }

        return Inertia::render('Settings/Billing/AccessBlocked', [
            'owners' => ActiveOwners::forCurrentTenant()
                ->map(fn ($owner) => ['name' => $owner->name, 'email' => $owner->email])
                ->values()
                ->all(),
        ])->toResponse($request);
    }

    private function respondReadOnly(Request $request): Response
    {
        $message = 'Your subscription is unpaid — update your payment method to restore full access.';

        // Simetric cu `EnsureDemoModeGuardrails` — un 403 brut într-un modal, pe o cerere
        // Inertia, se citește ca „aplicație stricată", nu ca „aplicație securizată".
        if ($request->header('X-Inertia')) {
            return back()->with('error', $message);
        }

        abort(403, $message);
    }
}
