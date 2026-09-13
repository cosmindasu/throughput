<?php

namespace App\Http\Resources;

use App\Models\Contact;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Contractul de props pentru Contacts/{Index,Show,Edit} — FR-CRM-02.
 *
 * `can` e calculat AICI, o singură dată prin `ContactPolicy` (plan §1.2 regula 2),
 * pentru fiecare contact — atât pentru rândul dintr-o listă cât și pentru pagina de
 * detaliu, ca cele două ecrane să nu recalculeze regula de ownership fiecare pe cont
 * propriu. `account`/`deals` ies doar dacă relația a fost încărcată explicit
 * (`whenLoaded`) — un N+1 pe fiecare rând al listei ar plăti exact ce cursor
 * pagination-ul (FR-PERF-03) încearcă să evite.
 *
 * @mixin Contact
 */
class ContactResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            'id' => $this->id,
            'firstName' => $this->first_name,
            'lastName' => $this->last_name,
            'fullName' => trim("{$this->first_name} {$this->last_name}"),
            'email' => $this->email,
            'phone' => $this->phone,
            'title' => $this->title,
            'isPrimary' => $this->is_primary,
            'optOut' => $this->opt_out,
            'accountId' => $this->account_id,
            'account' => $this->whenLoaded('account', fn () => $this->account === null ? null : [
                'id' => $this->account->id,
                'name' => $this->account->name,
            ]),
            'deals' => $this->whenLoaded('deals', fn () => $this->deals
                ->map(fn ($deal) => [
                    'id' => $deal->id,
                    'title' => $deal->title,
                    'status' => $deal->status,
                    'value' => $deal->value === null ? null : (float) $deal->value,
                ])
                ->all()),
            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
            'can' => [
                'edit' => $user !== null && $user->can('update', $this->resource),
                'delete' => $user !== null && $user->can('delete', $this->resource),
            ],
        ];
    }
}
