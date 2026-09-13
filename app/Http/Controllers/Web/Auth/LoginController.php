<?php

namespace App\Http\Controllers\Web\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Login cu email/parolă (plan §7.4) — `Auth::attempt` simplu, FĂRĂ Fortify.
 */
class LoginController extends Controller
{
    /**
     * Etichetele conturilor demo (FR-PUB-02, specs §4.2) sunt literale aici, nu derivate
     * din baza de date: pagina de login trebuie să le arate identic chiar și când
     * seed-ul de conturi demo nu a rulat încă (mediu proaspăt, CI) — butonul poate da
     * 404 la click (`DemoLoginController`), dar eticheta tot trebuie să existe.
     *
     * @var list<array{role: string, name: string, description: string}>
     */
    private const DEMO_ACCOUNTS = [
        [
            'role' => 'owner',
            'name' => 'Owner',
            'description' => 'Workspace switcher plus full access, including billing.',
        ],
        [
            'role' => 'manager',
            'name' => 'Manager',
            'description' => 'Full operational access and management of Agents and Viewers, without billing.',
        ],
        [
            'role' => 'agent',
            'name' => 'Agent',
            'description' => 'Lists open on your own accounts and deals; you edit only your own records and read the rest.',
        ],
        [
            'role' => 'viewer',
            'name' => 'Viewer',
            'description' => 'Read-only everywhere, but can still export lists to CSV and save personal views.',
        ],
    ];

    public function create(Request $request): Response
    {
        return Inertia::render('Auth/Login', [
            'demoMode' => (bool) config('throughput.demo.mode'),
            'canResetPassword' => true,
            'status' => $request->session()->get('status'),
            'demoAccounts' => self::DEMO_ACCOUNTS,
        ]);
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }
}
