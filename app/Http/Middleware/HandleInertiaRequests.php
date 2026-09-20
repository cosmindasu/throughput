<?php

namespace App\Http\Middleware;

use App\Http\Resources\UserResource;
use App\Http\Resources\WorkspaceResource;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Billing\SubscriptionAccessPolicy;
use App\Support\LocalePreference;
use App\Support\Members\UnassignedRecordsCounter;
use App\Support\ThemePreference;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),

            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                // FR-VIEW-02 — „notificare discretă", distinctă de succes/eroare: tonul
                // neutru contează, altfel ștergerea unei vederi „Team" de către un Manager
                // ar arăta ca o eroare a persoanei care doar deschide lista a doua zi.
                'notice' => fn () => $request->session()->get('notice'),
            ],

            // Prin config(), nu prin env(): entrypoint-ul de producție rulează
            // `config:cache`, iar după el `env()` întoarce implicitul — adică
            // bannerul de demo s-ar fi stins tăcut exact în producție (§22).
            'demoMode' => config('throughput.demo.mode'),

            // Sursă unică cu resources/views/app.blade.php (care citește același
            // cookie direct, înaintea oricărui prop Inertia, pentru clasa de pe
            // <html>): App\Support\ThemePreference — FR-PREF-03, nu o duplicare
            // a regulii de rezoluție în două locuri care se pot desincroniza.
            'theme' => ThemePreference::resolveForRequest($request),

            // Sursă unică cu resources/views/app.blade.php (`<html lang>`) și cu
            // App\Http\Middleware\SetLocale (App::setLocale()): App\Support\LocalePreference
            // — ADR-022, specs.md §15.8 FR-I18N-01. Nu e închis în closure: la fel ca
            // `theme` mai sus, nu depinde de `workspace`/`tenant`, deci se poate calcula
            // eager, fără riscul de „gol pe rute cu {workspace}" documentat mai jos pentru
            // `auth`/`workspace`.
            'locale' => LocalePreference::resolveForRequest($request),

            // Plan §1.2 regula 3 — sursă unică pentru `auth.user`, `workspace`,
            // `workspaces`, `navigation`. TOATE patru sunt închise în closures, NU calculate
            // eager aici: `Inertia\Middleware::handle()` apelează `share()` ÎNAINTE de
            // `$next($request)` (verificat în vendor), adică înainte ca
            // `ResolveWorkspace` să lege `tenant`/`memberships` în container și să
            // cheme `setPermissionsTeamId()`. Un closure se evaluează abia la
            // rezolvarea finală a paginii Inertia — după controller, ca și `flash` mai
            // sus. Fără closure, `workspace`/`can` ar ieși mereu goale pe orice rută cu
            // `{workspace}` în cale.
            'auth' => [
                'user' => fn () => $request->user() ? UserResource::make($request->user()) : null,
            ],

            'workspace' => fn () => app()->bound('tenant')
                ? WorkspaceResource::make(app('tenant'))
                : null,

            'workspaces' => fn () => $this->workspaces($request),

            // Permisiunile de NAVIGAȚIE (plan §7.4), calculate server-side cu
            // `$user->can()` — niciodată recalculate în React din rolul brut (§1.2
            // regula 2). Rolurile Spatie sunt per tenant (`setPermissionsTeamId`,
            // apelat de `ResolveWorkspace`): fără workspace rezolvat nu există niciun
            // tenant de verificat, deci array gol — nu o eroare tăcută pe tenantul
            // greșit. `(object)` pe ramura goală: JSON gol din `[]` ar ieși `[]`, nu
            // `{}`, și ar rupe `Record<string, boolean>` din contractul de props.
            //
            // Sub `navigation`, NU sub `can`: `can` e propul PER PAGINĂ din §1.2 regula 2
            // (`can.edit`, `can.move_stage`), iar Inertia combină props-urile comune cu
            // cele ale paginii superficial. Primul `can` de pagină ar fi înlocuit tot
            // obiectul, iar meniul principal ar fi dispărut exact pe ecranele cu acțiuni.
            'navigation' => fn () => $this->navigationPermissions($request),

            // FR-TEN-05 — indicatorul numeric permanent din `AppLayout` pe intrarea
            // „Unassigned", cât timp vederea nu e goală. Calculat DOAR pentru Owner/Manager
            // (`unassigned.view` mai sus, în `navigation`) — restul rolurilor nici nu văd
            // linkul, deci n-are rost să numărăm pentru ei. Cost măsurat în raportul
            // pachetului (`App\Support\Members\UnassignedRecordsCounter`).
            'unassignedRecordsCount' => fn () => app()->bound('tenant') && $request->user()?->can('unassigned.view')
                ? UnassignedRecordsCounter::count()
                : 0,

            // specs.md §12.2, plan §11 — bannerul de degradare pe 3 trepte în
            // `resources/js/Layouts/AppLayout.tsx`, pe ORICE pagină (nu doar Billing):
            // `null` până se rezolvă workspace-ul, la fel ca `workspace` mai sus. `status`
            // brut ȘI `accessLevel` calculat — bannerul are nevoie de AMBELE, fiindcă
            // `active` și `past_due` produc același `accessLevel` (Full, BR-BILL-03), dar
            // bannerul se arată DOAR pe `past_due`.
            'subscription' => fn () => app()->bound('tenant') ? $this->subscriptionState(app('tenant')) : null,
        ];
    }

    /**
     * @return array{status: ?string, accessLevel: string}
     */
    private function subscriptionState(Tenant $tenant): array
    {
        return [
            'status' => $tenant->subscription()?->stripe_status,
            'accessLevel' => SubscriptionAccessPolicy::levelFor($tenant)->value,
        ];
    }

    /**
     * @return list<array{slug: string, name: string}>
     */
    private function workspaces(Request $request): array
    {
        /** @var User|null $user */
        $user = $request->user();

        if ($user === null) {
            return [];
        }

        /** @var Collection<int, Membership> $memberships */
        $memberships = app()->bound('memberships')
            ? app('memberships')
            : Membership::forCurrentUserAcrossTenants($user->getAuthIdentifier());

        return $memberships
            ->map(fn (Membership $membership) => WorkspaceResource::summary($membership->tenant))
            ->values()
            ->all();
    }

    /**
     * @return array<string, bool>|object
     */
    private function navigationPermissions(Request $request): array|object
    {
        $user = $request->user();

        if ($user === null || ! app()->bound('tenant')) {
            return (object) [];
        }

        $permissions = [
            'accounts.view', 'contacts.view', 'deals.view', 'products.view', 'orders.view',
            'invoices.view', 'reports.view', 'imports.view', 'settings.view', 'billing.view',
            'members.view', 'api_tokens.view', 'unassigned.view',
        ];

        $computed = collect($permissions)
            ->mapWithKeys(fn (string $permission) => [$permission => $user->can($permission)]);

        // §7.4, rândul „Jurnal de activitate" (FR-AUD-03, lotul E) — Owner/Manager au
        // `activity_log.view`, Agent are `activity_log.view_own`; un `NavItem` verifică o
        // SINGURĂ permisiune (`resources/js/Layouts/AppLayout.tsx`), de aici cheia
        // combinată, calculată o dată aici, nu recompusă în React din rolul brut.
        $computed->put('activity_log.any_view', $user->can('activity_log.view') || $user->can('activity_log.view_own'));

        return $computed->all();
    }
}
