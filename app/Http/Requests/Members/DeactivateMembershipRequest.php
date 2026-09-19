<?php

namespace App\Http\Requests\Members;

use App\Models\Membership;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * §6.4.1 — dialogul din Gherkin US-TEN-03 are DOUĂ butoane: „Reassign and deactivate"
 * (`reassign = true`, cu `new_owner_user_id`) și „Deactivate anyway" (`reassign = false`).
 * `MembershipPolicy::deactivate()` decide dacă acțiunea e permisă deloc — validarea de aici
 * se ocupă doar de FORMA payload-ului.
 */
class DeactivateMembershipRequest extends FormRequest
{
    /** Policy-ul se verifică explicit în controller, pe instanța `Membership` din rută. */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reassign' => ['required', 'boolean'],
            'new_owner_user_id' => [
                'required_if:reassign,true',
                'nullable',
                'string',
                $this->activeMemberOtherThanTargetRule(),
            ],
        ];
    }

    public function shouldReassign(): bool
    {
        return $this->boolean('reassign');
    }

    /**
     * Nu `Rule::exists`, pentru ACELAȘI motiv ca `ReassignOwnerRequest::activeMembershipRule()`
     * (politica proprie a lui `memberships`, ADR-014 pct. 2, ar da un fals pozitiv pe SQL brut).
     */
    private function activeMemberOtherThanTargetRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! $this->boolean('reassign')) {
                return;
            }

            if ($value === $this->route('membership')?->user_id) {
                $fail('The new owner cannot be the member being deactivated.');

                return;
            }

            $isActiveMember = Membership::query()
                ->where('user_id', $value)
                ->where('status', Membership::STATUS_ACTIVE)
                ->exists();

            if (! $isActiveMember) {
                $fail('The selected owner is not an active member of this workspace.');
            }
        };
    }
}
