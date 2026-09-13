<?php

namespace App\Http\Requests\Deals;

use App\Models\Deal;
use App\Models\Membership;
use App\Models\Scopes\TenantScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Edit: titlu, valoare, dată estimată, contact principal, owner (doar cu `changeOwner`).
 * `stage_id` NU e aici — etapa nu se schimbă din formular (§9 task), doar din
 * `DealStageController::move()`.
 *
 * Abatere de scop, semnalată explicit: task-ul Pachetului C listează și „cont" printre
 * câmpurile editabile din Edit. NU e acceptat aici — pagina de Accounts (căutare/listă)
 * e alt pachet, încă nemerge în acest branch, iar un selector de cont fără căutare
 * (ULID tastat de mână) ar fi inutilizabil și netestabil. Contul unui deal existent
 * rămâne fix după creare; schimbarea contului, dacă va fi cerută, ar trebui să fie o
 * acțiune explicită („Move to account…"), nu un câmp tăcut într-un formular general.
 */
class UpdateDealRequest extends FormRequest
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
        /** @var Deal $deal */
        $deal = $this->route('deal');

        return [
            'title' => ['required', 'string', 'max:255'],
            'value' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'currency' => ['nullable', 'string', 'size:3'],
            'expected_close_date' => ['nullable', 'date'],
            'primary_contact_id' => [
                'nullable', 'string',
                Rule::exists('contacts', 'id')->where(fn ($query) => $query
                    ->where('tenant_id', $tenantId)
                    ->where('account_id', $deal->account_id)),
            ],
            'owner_user_id' => [
                'nullable', 'string',
                Rule::exists('memberships', 'user_id')->where(fn ($query) => $query
                    ->where('tenant_id', $tenantId)
                    ->where('status', Membership::STATUS_ACTIVE)),
            ],
        ];
    }
}
