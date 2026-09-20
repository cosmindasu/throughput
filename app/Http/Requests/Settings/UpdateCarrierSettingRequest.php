<?php

namespace App\Http\Requests\Settings;

use App\Models\TenantCarrierSetting;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * „Save & activate" pe Settings → Shipping (FR-ORD-06). Doar FORMA payload-ului aici —
 * regula de business (o cheie Shippo obligatorie înainte de activare, BR-ORD-03) e
 * verificată de `App\Actions\Shipping\ActivateCarrierAction`, ca mesajul ei să apară
 * lângă `credentials.api_key` indiferent de unde vine (§7.3, `Field`/`aria-describedby`).
 *
 * Audit de securitate P1 — FR-ORD-01/BR-DEMO-03 promiteau „exclusiv chei sandbox" în TREI
 * locuri (specs.md, hint-ul din UI, docblock-ul `ShippoCarrier`) și nu erau verificate în
 * NICIUNUL: `credentials.api_key` accepta orice string ≤ 255 caractere, inclusiv o cheie
 * `shippo_live_...` reală. Agravant: `DemoLoginController` autentifică orice vizitator
 * anonim ca Owner demo (`DEMO_MODE=true`), deci ecranul ar deveni un oracol gratuit
 * pentru verificat dacă o cheie furată mai e live. Stratul 1 (UX, mesaj imediat): regexul
 * de mai jos. Stratul 2 (defensiv, ÎNAINTE de orice apel extern): `ActivateCarrierAction`
 * repetă aceeași verificare la scriere — vezi docblock-ul de-acolo pentru argumentul
 * alegerii ei față de `CarrierResolver`.
 */
class UpdateCarrierSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage', TenantCarrierSetting::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'provider' => ['required', Rule::in(['demo', 'shippo'])],
            // `sometimes` — formularul mascat (BR-AUD-01: credențialele nu se retrimit
            // niciodată în clar) omite `credentials` cu totul cât timp utilizatorul doar
            // apasă „Activate" pe un furnizor deja configurat, fără să schimbe cheia.
            'credentials' => ['sometimes', 'array'],
            'credentials.api_key' => ['sometimes', 'nullable', 'string', 'max:255', 'regex:/^shippo_test_/'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'credentials.api_key.regex' => 'Only Shippo sandbox keys (shippo_test_...) are accepted in this deployment — never a live key.',
        ];
    }

    public function providerInput(): string
    {
        return (string) $this->string('provider');
    }

    /**
     * `null` (nu `[]`) când formularul n-a trimis deloc `credentials` — semnalul pe care
     * `ActivateCarrierAction` îl citește ca „păstrează ce era deja pe rând", nu „șterge
     * cheia existentă".
     *
     * @return array<string, mixed>|null
     */
    public function credentialsInput(): ?array
    {
        return $this->has('credentials') ? $this->array('credentials') : null;
    }
}
