<?php

namespace App\Http\Controllers\Web\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Inertia;
use Inertia\Response;

/**
 * FR-PUB-05, BR-PUB-02, specs §4.5 — „Forgot password?".
 *
 * Mesaj identic indiferent dacă adresa există (anti-enumerare, OWASP Forgot Password
 * Cheat Sheet) + rate-limit propriu per IP (5/oră), independent de throttle-ul intern
 * al brokerului (60s/email, implicit Laravel 13 — BR-PUB-02).
 */
class PasswordResetLinkController extends Controller
{
    private const GENERIC_STATUS = "If an account exists for this email, we've sent a reset link.";

    private const IP_RATE_LIMIT_MAX_ATTEMPTS = 5;

    private const IP_RATE_LIMIT_DECAY_SECONDS = 3600;

    public function create(Request $request): Response
    {
        return Inertia::render('Auth/ForgotPassword', [
            'status' => $request->session()->get('status'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate(['email' => ['required', 'string', 'email']]);

        $throttleKey = 'forgot-password:'.$request->ip();

        // Singurul răspuns din acest flux care se distinge de mesajul generic de mai
        // jos: nu ține de existența contului, doar de volumul de cereri de pe aceeași
        // mașină (criteriul de acceptanță din specs §4.5 cere explicit un răspuns
        // distinct la a 6-a cerere/oră).
        if (RateLimiter::tooManyAttempts($throttleKey, self::IP_RATE_LIMIT_MAX_ATTEMPTS)) {
            abort(429, 'Too many password reset requests. Please try again later.');
        }

        RateLimiter::hit($throttleKey, self::IP_RATE_LIMIT_DECAY_SECONDS);

        // Rezultatul brokerului (RESET_LINK_SENT / INVALID_USER / RESET_THROTTLED) nu
        // ajunge NICIODATĂ în răspuns — exact mecanismul de enumerare pe care OWASP
        // cere să fie evitat.
        Password::broker()->sendResetLink($request->only('email'));

        return back()->with('status', self::GENERIC_STATUS);
    }
}
