<?php

namespace App\Http\Controllers\Web\Settings;

use App\Actions\Members\InviteMemberAction;
use App\Actions\Members\RevokeInvitationAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Members\InviteMemberRequest;
use App\Models\Membership;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Settings → Members, partea de INVITAȚII (§6.4, US-TEN-01). Separat de `MembersController`
 * (care ține lista și dezactivarea, US-TEN-02/03) fiindcă invitația e singurul flux din tot
 * produsul care trimite email către o adresă arbitrară — specs.md §22.3, BR-DEMO-02 — și
 * merită să fie citibil ca atare, nu îngropat într-un controller de listă.
 *
 * O invitație E un rând `memberships` cu `status = 'pending'` (decizia de formă e
 * argumentată în `App\Actions\Members\InviteMemberAction`), deci legarea de rută
 * `{membership}` e aceeași ca pentru un membru — cu aceleași garanții: un id dintr-un ALT
 * tenant e 404 înainte de controller (global scope + RLS, §18.5).
 *
 * Refuzurile de regulă se întorc cu `withErrors()`, NU cu `abort(403)`: pe o cerere Inertia
 * un 403 brut se randează ca „aplicație stricată" — același raționament, citat verbatim, ca
 * în `MembersController::deactivate()` și în `EnsureDemoModeGuardrails`.
 */
final class InvitationController extends Controller
{
    public function __construct(
        private readonly InviteMemberAction $invite,
        private readonly RevokeInvitationAction $revoke,
    ) {}

    public function store(InviteMemberRequest $request): RedirectResponse
    {
        $decision = Gate::forUser($request->user())
            ->inspect('inviteWithRole', [Membership::class, $request->invitedRole()]);

        if ($decision->denied()) {
            // Cheia `role`, nu una generică: refuzul privește EXACT câmpul „Role" din
            // formular („Only an Owner can invite another Owner"), deci `Field` îl poate
            // lega de select prin `aria-describedby`, în loc să-l arate ca alertă ruptă de
            // controlul care l-a cauzat (audit de accesibilitate P1, pct. 1).
            return back()->withErrors(['role' => $decision->message()])->withInput();
        }

        $result = $this->invite->execute(
            actor: $request->user(),
            email: $request->invitedEmail(),
            role: $request->invitedRole(),
            request: $request,
        );

        $email = $request->invitedEmail();

        return redirect()
            ->route('settings.members.index')
            ->with('success', $result['isNewUser']
                ? __('flash.invitations.sent_new_user', ['email' => $email])
                : __('flash.invitations.sent_existing_user', ['email' => $email]));
    }

    public function resend(Request $request, Membership $membership): RedirectResponse
    {
        $decision = Gate::forUser($request->user())->inspect('resendInvitation', $membership);

        if ($decision->denied()) {
            return back()->withErrors(['invitation' => $decision->message()]);
        }

        $this->invite->resend($request->user(), $membership);

        $email = $membership->user?->email ?? __('flash.invitations.email_fallback_invited');

        return redirect()
            ->route('settings.members.index')
            ->with('success', __('flash.invitations.resent', ['email' => $email]));
    }

    public function destroy(Request $request, Membership $membership): RedirectResponse
    {
        $decision = Gate::forUser($request->user())->inspect('revokeInvitation', $membership);

        if ($decision->denied()) {
            return back()->withErrors(['invitation' => $decision->message()]);
        }

        $email = $membership->user?->email ?? __('flash.invitations.email_fallback_generic');

        $this->revoke->execute($request->user(), $membership, $request);

        return redirect()
            ->route('settings.members.index')
            ->with('success', __('flash.invitations.revoked', ['email' => $email]));
    }
}
