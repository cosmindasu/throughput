<?php

namespace App\Http\Requests\Members;

use App\Support\Permissions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * US-TEN-02 — „schimb rolul unui Manager în Viewer". Doar FORMA; BR-TEN-01 (ultimul Owner)
 * și BR-TEN-02 (cine poate atinge un Owner) sunt în `MembershipPolicy::updateRole()`,
 * rulat a doua oară sub blocare în `UpdateMemberRoleAction`.
 */
class UpdateMemberRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'role' => ['required', 'string', Rule::in(Permissions::roles())],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'role.required' => 'Choose a role.',
            'role.in' => 'Choose one of the four workspace roles.',
        ];
    }

    public function newRole(): string
    {
        return (string) $this->string('role');
    }
}
