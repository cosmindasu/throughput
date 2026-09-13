<?php

namespace App\Http\Requests\Deals;

use App\Models\Membership;
use App\Models\Scopes\TenantScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * US-DEAL-01 — creare deal (din pagina unui cont, `?account=`). Autorizarea propriu-zisă
 * (`deals.create`) rămâne în controller, cu `Gate::authorize()` — tiparul deja folosit de
 * `LoginRequest::authorize()` din acest proiect (`return true`, verificare explicită
 * altundeva), nu repetat aici.
 *
 * `Rule::exists()` rulează SQL brut, care OCOLEȘTE global scope-ul Eloquent
 * (`BelongsToTenant`): fără clauza `tenant_id` explicită, un ULID valid dintr-un ALT
 * tenant ar trece validarea — RLS l-ar respinge oricum la INSERT (§ADR-003), dar un 500
 * de la Postgres e un răspuns mai opac decât un 422 clar aici.
 */
class StoreDealRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tenantId = TenantScope::requireCurrentTenantId();

        return [
            'account_id' => [
                'required', 'string',
                Rule::exists('accounts', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)),
            ],
            'primary_contact_id' => [
                'nullable', 'string',
                // §9 task: contactul principal trebuie să fie DINTRE contactele contului ales.
                Rule::exists('contacts', 'id')->where(fn ($query) => $query
                    ->where('tenant_id', $tenantId)
                    ->where('account_id', $this->input('account_id'))),
            ],
            'title' => ['required', 'string', 'max:255'],
            'value' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'currency' => ['nullable', 'string', 'size:3'],
            'expected_close_date' => ['nullable', 'date'],
            'owner_user_id' => [
                'nullable', 'string',
                // Membru ACTIV al tenantului curent — `users` e globală, fără `tenant_id`
                // (§19.1), deci un `exists` direct pe `users` ar accepta orice cont din
                // toată aplicația, nu doar colegii din acest workspace.
                Rule::exists('memberships', 'user_id')->where(fn ($query) => $query
                    ->where('tenant_id', $tenantId)
                    ->where('status', Membership::STATUS_ACTIVE)),
            ],
        ];
    }
}
