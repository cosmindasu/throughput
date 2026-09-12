<?php

namespace App\Http\Middleware;

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

            // auth.user, workspace, workspaces, can — Faza 1 (§1.2 regula 3
            // din plan-implementare.md): nu există încă tenancy/RBAC.
        ];
    }
}
