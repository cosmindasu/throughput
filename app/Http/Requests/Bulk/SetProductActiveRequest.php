<?php

namespace App\Http\Requests\Bulk;

use App\Support\ListQuery;
use Illuminate\Foundation\Http\FormRequest;

/**
 * §13.5 — activare/dezactivare în masă a produselor selectate.
 */
class SetProductActiveRequest extends FormRequest
{
    /** Policy-ul decide (`BulkOperationController::setProductActive()`, `ProductPolicy::bulkWrite()`). */
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
            'active' => ['required', 'boolean'],
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
