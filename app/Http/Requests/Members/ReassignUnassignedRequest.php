<?php

namespace App\Http\Requests\Members;

use App\Models\Membership;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `Unassigned/Index` — „Reassign to…" pe toată vederea (§6.4.1, FR-TEN-05). Autorizarea
 * reală e Policy (`UnassignedController::reassign()`); aici doar forma payload-ului.
 */
class ReassignUnassignedRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'new_owner_user_id' => ['required', 'string', $this->activeMembershipRule()],
        ];
    }

    /** Ca `ReassignOwnerRequest::activeMembershipRule()` — vezi acolo motivul. */
    private function activeMembershipRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
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
