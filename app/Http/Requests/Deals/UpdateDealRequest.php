<?php

namespace App\Http\Requests\Deals;

use App\Models\Deal;
use App\Models\Membership;
use App\Models\Scopes\TenantScope;
use App\Support\Contacts\AccountBelongsToTenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Edit: cont, titlu, valoare, dată estimată, contact principal, owner (doar cu
 * `changeOwner`). `stage_id` NU e aici — etapa nu se schimbă din formular (§9 task),
 * doar din `DealStageController::move()`.
 *
 * `account_id` a devenit editabil (cerință explicită a proprietarului, care înlocuiește
 * abaterea anterioară — pagina de Accounts e acum pe branch și `AccountCombobox` există):
 * aceleași reguli ca la `StoreDealRequest`, prin `AccountBelongsToTenant` — global scope
 * Eloquent (ADR-003) + RLS, ambele straturi, nu doar validarea de client din combobox.
 *
 * §7.5: nu există o îngustare ABAC separată pe „ce cont poate folosi un Agent" —
 * `AccountPolicy::view()`/`viewAny()` nu se îngustează pentru Agent (doar `update()` și
 * `delete()` ale CONTULUI se îngustează la înregistrările proprii), deci orice cont din
 * tenantul curent e un cont „pe care utilizatorul are voie să-l folosească" pentru un
 * deal. Restricția de proprietate rămâne pe DEAL (`DealPolicy::isWithinOwnRecords()`,
 * verificată în `Gate::authorize('update', $deal)` din controller), nu pe cont.
 *
 * `primary_contact_id` se validează față de `account_id`-ul TRIMIS în cererea curentă,
 * nu față de `$deal->account_id` (vechiul cont) — altfel un contact valid pentru contul
 * NOU ar fi respins pe baza contului VECHI, iar un contact al contului vechi ar trece
 * din greșeală lângă un cont nou greșit.
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

        return [
            'account_id' => ['required', 'string', new AccountBelongsToTenant],
            'title' => ['required', 'string', 'max:255'],
            'value' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'currency' => ['nullable', 'string', 'size:3'],
            'expected_close_date' => ['nullable', 'date'],
            'primary_contact_id' => [
                'nullable', 'string',
                // §20.5 — un contact anonimizat nu mai e o opțiune NOUĂ validă, dar
                // `keepsExistingPrimaryContact()` lasă neatinsă legătura deja existentă a
                // deal-ului cu unul (anonimizat DUPĂ ce a fost legat), ca o editare de rutină
                // care nu atinge deloc acest câmp să nu o rupă tăcut.
                Rule::exists('contacts', 'id')->where(fn ($query) => $query
                    ->where('tenant_id', $tenantId)
                    ->where('account_id', $this->input('account_id'))
                    ->when(! $this->keepsExistingPrimaryContact(), fn ($q) => $q->whereNull('anonymized_at'))),
            ],
            'owner_user_id' => [
                'nullable', 'string',
                Rule::exists('memberships', 'user_id')->where(fn ($query) => $query
                    ->where('tenant_id', $tenantId)
                    ->where('status', Membership::STATUS_ACTIVE)),
            ],
        ];
    }

    /**
     * „Existentă" înseamnă STRICT id-ul deja pe deal ȘI același cont — id-ul unui contact
     * anonimizat, chiar dacă a fost vreodată legitim pe alt deal sau pe acest deal înainte
     * de o schimbare de cont, rămâne respins de ramura `whereNull('anonymized_at')` de mai
     * sus. `Deals/Edit.tsx` retrimite mereu `deal.primaryContact.id`, neschimbat, cât timp
     * utilizatorul nu alege alt contact și nu schimbă contul.
     */
    private function keepsExistingPrimaryContact(): bool
    {
        /** @var Deal $deal */
        $deal = $this->route('deal');

        return $deal->primary_contact_id !== null
            && $this->input('primary_contact_id') === $deal->primary_contact_id
            && $this->input('account_id') === $deal->account_id;
    }
}
