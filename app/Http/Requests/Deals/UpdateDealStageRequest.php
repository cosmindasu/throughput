<?php

namespace App\Http\Requests\Deals;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `PATCH /deals/{deal}/stage`. Validare de FORMĂ doar — `to_stage_id` existent și
 * aparținând tenantului curent se verifică în `DealStageController::move()` via
 * `Stage::findOrFail()` (respectă global scope + RLS, deci 404 pe un ULID din alt
 * tenant). Regulile de BUSINESS („Won fără value", „Lost fără motiv", „aceeași etapă",
 * „alt pipeline") stau în `MoveDealStageAction`, nu aici — au nevoie de rândul blocat
 * (`lockForUpdate`) din interiorul tranzacției, nu doar de forma cererii.
 */
class UpdateDealStageRequest extends FormRequest
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
        return [
            'to_stage_id' => ['required', 'string'],
            // FR-DEAL-03 — lista închisă; `nullable` fiindcă e obligatoriu doar când
            // etapa țintă e `is_lost` (verificat în acțiune, care cunoaște etapa).
            'lost_reason' => ['nullable', 'string', Rule::in(['price', 'competition', 'timing', 'other'])],
        ];
    }
}
