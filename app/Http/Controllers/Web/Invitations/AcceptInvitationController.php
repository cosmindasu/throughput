<?php

namespace App\Http\Controllers\Web\Invitations;

use App\Actions\Members\AcceptInvitationAction;
use App\Actions\Members\PendingInvitation;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * US-TEN-01 — capătul public al invitației: `/invitations/{workspace}/{token}`.
 *
 * Rută PUBLICĂ, deliberat FĂRĂ middleware-ul `guest`: invitatul poate fi deja autentificat
 * (e membru în altă organizație și tocmai a primit invitația într-a treia). Cu `guest`, un
 * utilizator logat ar fi fost redirecționat spre dashboard-ul lui, fără nicio explicație și
 * fără să accepte nimic — cazul cel mai probabil în demo, unde vizitatorul e deja logat ca
 * Owner când trimite invitația.
 *
 * Nu e nici în grupul cu `{workspace}` (`routes/web.php`): acela cere `auth` +
 * `session.context` + membership ACTIV, adică exact ce invitatul încă nu are.
 *
 * De ce slug-ul e în cale, și de ce asta nu e o autorizare: vezi
 * `App\Actions\Members\PendingInvitation`.
 */
final class AcceptInvitationController extends Controller
{
    public function __construct(
        private readonly AcceptInvitationAction $accept,
    ) {}

    public function show(string $workspace, string $token): Response
    {
        $invitation = PendingInvitation::resolve($workspace, $token);

        // 404 identic pentru „slug inexistent", „token inventat" și „invitație deja
        // acceptată/revocată": un vizitator anonim nu trebuie să poată distinge între ele
        // (§18.5 — enumerarea e jumătate din BOLA).
        abort_if($invitation === null, 404);

        return Inertia::render('Invitations/Accept', $this->props($invitation, $token));
    }

    public function accept(Request $request, string $workspace, string $token): RedirectResponse
    {
        $invitation = PendingInvitation::resolve($workspace, $token);

        abort_if($invitation === null, 404);

        // Expirarea e o EROARE DE STARE, nu una de câmp: pagina o arată deja, cu numele
        // workspace-ului și cu îndemnul de a cere un link nou. Un `POST` pe un link expirat
        // (tab lăsat deschis peste fereastra de 7 zile) se întoarce pe aceeași pagină, care
        // îl va randa în starea „expired".
        if ($invitation->isExpired()) {
            return back()->withErrors(['token' => 'This invitation has expired. Ask for a new one.']);
        }

        $needsProfile = $invitation->needsProfile();

        $validated = $request->validate($needsProfile ? [
            'name' => ['required', 'string', 'max:255'],
            // `Password::defaults()` — aceleași reguli ca la resetarea parolei (FR-PUB-05),
            // dintr-un singur loc, nu o a doua listă care poate diverge.
            'password' => ['required', 'confirmed', Password::defaults()],
        ] : []);

        try {
            $user = $this->accept->execute(
                invitation: $invitation,
                name: $needsProfile ? (string) ($validated['name'] ?? '') : null,
                password: $needsProfile ? (string) ($validated['password'] ?? '') : null,
                request: $request,
            );
        } catch (RuntimeException) {
            // Reverificarea sub blocare a picat: invitația a fost revocată, retrimisă (token
            // nou) sau a expirat între afișarea paginii și trimiterea formularului.
            throw ValidationException::withMessages([
                'token' => 'This invitation is no longer valid. Ask for a new one.',
            ]);
        }

        // Sesiune GOLITĂ, nu doar regenerată, înainte de a loga invitatul.
        //
        // `regenerate()` schimbă id-ul dar PĂSTREAZĂ datele, iar `AuthenticateSession` (activ
        // global, FR-PUB-05) ține în sesiune `password_hash_web` — hash-ul parolei
        // utilizatorului de dinainte. Cazul nu e teoretic, e chiar cel obișnuit în demo:
        // vizitatorul e deja logat ca Owner când deschide linkul. Cu hash-ul vechi rămas în
        // sesiune, invitatul ar fi fost delogat la PRIMA cerere de după acceptare — un
        // redirect către dashboard urmat instant de `/login`, fără nicio explicație.
        // `invalidate()` golește tot și migrează sesiunea; `regenerateToken()` dă un CSRF nou
        // (cererea curentă a trecut deja de verificare).
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        Auth::guard('web')->login($user);

        return redirect("/{$invitation->tenant->slug}/dashboard")
            ->with('success', "You're in — welcome to {$invitation->tenant->name}.");
    }

    /**
     * @return array<string, mixed>
     */
    private function props(PendingInvitation $invitation, string $token): array
    {
        return [
            'workspaceName' => $invitation->tenant->name,
            'email' => (string) $invitation->membership->user?->email,
            'roleName' => (string) $invitation->membership->user?->getRoleNames()->first(),
            'expired' => $invitation->isExpired(),
            'needsProfile' => $invitation->needsProfile(),
            'acceptUrl' => "/invitations/{$invitation->tenant->slug}/{$token}",
        ];
    }
}
