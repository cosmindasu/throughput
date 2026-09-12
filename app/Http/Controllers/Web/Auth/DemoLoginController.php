<?php

namespace App\Http\Controllers\Web\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * FR-PUB-02, BR-PUB-01 — autentificare „un click" pe conturile demo, activă DOAR când
 * `config('throughput.demo.mode')` e true. Citit din config, NICIODATĂ din `env()`
 * direct: motivul e documentat în `config/throughput.php` (s-ar stinge tăcut la
 * `config:cache` în producție).
 *
 * `{role}` e restricționat la owner|manager|agent|viewer la nivel de rută
 * (`whereIn`) — orice altă valoare nu ajunge nici măcar aici (404 de rutare).
 */
class DemoLoginController extends Controller
{
    public function store(Request $request, string $role): RedirectResponse
    {
        // 404, nu 403: în afara demo-ului, ruta asta nu trebuie nici măcar confirmată
        // ca existentă (simetric cu 404-ul de workspace din ResolveWorkspace).
        abort_unless((bool) config('throughput.demo.mode'), 404);

        $user = User::query()->where('email', "demo.{$role}@throughput.dev")->first();

        // Cont demo lipsă (seed-ul încă nu a rulat) — tot 404, nu 500.
        abort_if($user === null, 404);

        Auth::login($user);

        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }
}
