<?php

namespace App\Http\Controllers\Web\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\NewPasswordRequest;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * FR-PUB-05, specs §4.5 — finalizarea resetării parolei.
 */
class NewPasswordController extends Controller
{
    public function create(Request $request, string $token): Response
    {
        return Inertia::render('Auth/ResetPassword', [
            'token' => $token,
            'email' => (string) $request->query('email', ''),
        ]);
    }

    public function store(NewPasswordRequest $request): RedirectResponse
    {
        $status = Password::broker()->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user) use ($request): void {
                $user->forceFill([
                    'password' => Hash::make($request->string('password')->value()),
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($user));

                // specs §4.5 — invalidare NECONDIȚIONATĂ a celorlalte sesiuni, fără
                // prompt către utilizator. Fluxul e anonim (nicio sesiune autentificată
                // pe cererea curentă): `setUser()` leagă utilizatorul de guard doar
                // pentru durata acestei cereri, fără să persiste o sesiune nouă pe acest
                // dispozitiv — altfel `logoutOtherDevices()` ar ieși din funcție tăcut
                // (vezi `SessionGuard::logoutOtherDevices()`, care nu face nimic dacă
                // `$this->user()` e null). Invalidarea efectivă a celorlalte sesiuni se
                // întâmplă la request-ul LOR următor, prin middleware-ul
                // `AuthenticateSession` (activ global — vezi raportul final).
                Auth::guard('web')->setUser($user);
                Auth::guard('web')->logoutOtherDevices($request->string('password')->value());
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => [trans($status)],
            ]);
        }

        return redirect()->route('login')->with('status', trans($status));
    }
}
