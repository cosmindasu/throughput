<?php

namespace App\Http\Requests\Members;

use App\Models\Membership;
use App\Models\User;
use App\Support\Permissions;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * US-TEN-01, §6.4 — forma payload-ului de invitare. DREPTUL de a invita (și de a invita CU
 * un anumit rol, BR-TEN-02) e al lui `MembershipPolicy::inviteWithRole()`, verificat
 * explicit în controller ca să poată întoarce mesajul refuzului în dialog.
 */
class InviteMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalizare ÎNAINTE de validare, nu în acțiune: `unique` pe `users.email` e
     * sensibil la majuscule în PostgreSQL, deci „Ana@Exemplu.com" și „ana@exemplu.com" ar
     * fi ajuns două identități globale distincte pentru aceeași persoană — iar a doua ar
     * fi trecut de verificarea „e deja membru" de mai jos.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('email')) {
            $this->merge(['email' => Str::lower(trim((string) $this->input('email')))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email:rfc', 'max:255', $this->notAlreadyInWorkspaceRule()],
            'role' => ['required', 'string', Rule::in(Permissions::roles())],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            // FR-I18N-04 — suprascrieri de formular, din `lang/{en,fr}/forms.php`. Ele
            // există fiindcă mesajul generic al framework-ului („The email field is
            // required.") e corect, dar nu spune ce să faci; de aceea sunt la imperativ.
            'email.required' => __('forms.members.invite.email_required'),
            'email.email' => __('forms.members.invite.email_email'),
            'role.required' => __('forms.members.invite.role_required'),
            'role.in' => __('forms.members.role_in'),
        ];
    }

    public function invitedEmail(): string
    {
        return (string) $this->string('email');
    }

    public function invitedRole(): string
    {
        return (string) $this->string('role');
    }

    /**
     * Nu `Rule::unique`, pentru ACELAȘI motiv ca `DeactivateMembershipRequest`: `memberships`
     * are politica RLS proprie (ADR-014 pct. 2), iar o interogare pe SQL brut, în afara
     * modelului, ar ocoli global scope-ul și ar putea vedea rândurile ALTUI tenant prin
     * ramura `app.user_id` — un fals pozitiv „e deja membru" pentru cineva care e membru în
     * altă organizație. `Membership::query()` e scopat corect de amândouă straturile.
     *
     * Un rând `deactivated` NU e un motiv de refuz: re-invitarea cuiva care a plecat și
     * s-a întors e un flux real, iar `unique(tenant_id, user_id)` face imposibil un al
     * doilea rând — invitația îl refolosește pe cel existent.
     */
    private function notAlreadyInWorkspaceRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $user = User::query()->where('email', $value)->first();

            if ($user === null) {
                return;
            }

            $membership = Membership::query()->where('user_id', $user->getKey())->first();

            if ($membership === null) {
                return;
            }

            if ($membership->status === Membership::STATUS_ACTIVE) {
                $fail(__('rules.members.already_a_member'));

                return;
            }

            if ($membership->status === Membership::STATUS_PENDING) {
                $fail(__('rules.members.invitation_already_pending'));
            }
        };
    }
}
