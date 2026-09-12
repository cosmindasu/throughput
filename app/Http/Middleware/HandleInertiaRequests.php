<?php

namespace App\Http\Middleware;

use App\Http\Resources\UserResource;
use App\Http\Resources\WorkspaceResource;
use App\Models\Membership;
use App\Models\User;
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
            ],

            // Prin config(), nu prin env(): entrypoint-ul de producție rulează
            // `config:cache`, iar după el `env()` întoarce implicitul — adică
            // bannerul de demo s-ar fi stins tăcut exact în producție (§22).
            'demoMode' => config('throughput.demo.mode'),

            // Cookie-ul `theme` e sursa de adevăr pentru randarea fără
            // licărire (FR-PREF-03) — vezi și resources/views/app.blade.php,
            // care citește același cookie direct, înaintea oricărui prop
            // Inertia, pentru clasa de pe <html>.
            'theme' => $request->cookie('theme') === 'light' ? 'light' : 'dark',

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
            'invoices.view', 'reports.view', 'settings.view', 'billing.view', 'members.view',
            'api_tokens.view',
        ];

        return collect($permissions)
            ->mapWithKeys(fn (string $permission) => [$permission => $user->can($permission)])
            ->all();
    }
}
