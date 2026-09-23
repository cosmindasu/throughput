<?php

namespace App\Http\Requests\Bulk;

use App\Support\ListQuery;
use Illuminate\Foundation\Http\FormRequest;

/**
 * GDPR-04, §13.5 (Art. 17, RTBF) — ștergere/anonimizare în masă pe contactele selectate.
 * Fără payload propriu, ca `CancelDraftOrdersRequest`: decizia „anonimizare vs. ștergere
 * fizică" nu vine din cerere, e per contact (`App\Support\Contacts\ContactErasure`).
 */
class DeleteContactsRequest extends FormRequest
{
    /** Policy-ul decide (`ContactBulkOperationController::delete()`, `ContactPolicy::bulkDelete()`). */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'selectAllMatching' => ['required', 'boolean'],
            'ids' => ['required_if:selectAllMatching,false', 'array', 'max:'.ListQuery::PER_PAGE],
            'ids.*' => ['string', 'ulid'],
            // FR-BULK-01 — clientul trimite `true` doar după dialogul de confirmare.
            'confirmed' => ['sometimes', 'boolean'],
        ];
    }

    /** @return list<string>|null null = tot filtrul curent (mod „Select all matching") */
    public function idsOrNull(): ?array
    {
        return $this->boolean('selectAllMatching') ? null : $this->input('ids', []);
    }

    public function confirmed(): bool
    {
        return $this->boolean('confirmed');
    }
}
