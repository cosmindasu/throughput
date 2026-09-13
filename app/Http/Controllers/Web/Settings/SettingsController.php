<?php

namespace App\Http\Controllers\Web\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Shell de Settings (plan §7.4 — „submeniuri vizibile/ascunse per `can`, paginile
 * propriu-zise vin în Faza 5") + Preferences (specs.md §15.6, construită acum).
 *
 * Toate cele patru roluri au `settings.view` (App\Support\Permissions) — pagina în
 * sine e mereu accesibilă unui membru al workspace-ului; ce diferă pe rol e SETUL de
 * secțiuni vizibile în ea, calculat aici, server-side (§7.3, FR-RBAC-01), nu recalculat
 * din rol în React.
 */
class SettingsController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Settings/Index', [
            // O secțiune fără drept LIPSEȘTE din interfață — React filtrează pe aceste
            // chei exact ca `AppLayout` pe `navigation` (§7.3, FR-RBAC-01), nu recalculează
            // nimic din rolul brut.
            'can' => [
                // Membri și roluri — Owner/Manager (matricea §7.4); Agent/Viewer nu au
                // `members.view` deloc (App\Support\Permissions::forRoles()).
                'members' => $user->can('members.view'),

                // Doar Owner — criteriul de acceptanță §7.3: „Manager NU vede Billing &
                // Subscription". Deliberat DIFERIT de rândul brut al matricei §7.4 (vezi
                // comentariul din Permissions::forRoles() pentru Manager).
                'billing' => $user->can('billing.view'),

                'apiTokens' => $user->can('api_tokens.view'),

                // Owner, Manager, Viewer — nu Agent (matricea §7.4: Agent are „—" pe
                // configurarea de pipeline/etape, Viewer are „R").
                'pipeline' => $user->can('pipelines.view'),

                // Mereu true — BR-PREF-02: comutarea temei nu e o acțiune de scriere de
                // business, disponibilă tuturor rolurilor. Cheie explicită (nu omisă),
                // ca forma props-ului să rămână uniformă pentru React (§1.2 regula 2).
                'preferences' => true,
            ],
        ]);
    }

    /**
     * Preferences — vizibilă TUTUROR rolurilor, inclusiv Viewer (BR-PREF-02): fără
     * niciun `can` de verificat, comutarea de temă nu e o acțiune de scriere de business.
     */
    public function preferences(): Response
    {
        return Inertia::render('Settings/Preferences');
    }
}
