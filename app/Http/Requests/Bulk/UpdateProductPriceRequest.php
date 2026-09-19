<?php

namespace App\Http\Requests\Bulk;

use App\Support\ListQuery;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * §13.5 — preț în masă pe variantele produselor selectate: procent sau sumă fixă, creștere
 * sau scădere (specs.md §13.5). Un procent e plafonat la 100 — o „scădere" mai mare ar
 * fi oricum absorbită de `GREATEST(..., 0)` din `UpdatePriceAction`, dar refuzul explicit
 * aici e mai clar decât un rezultat tăcut de 0 pentru orice procent peste 100.
 */
class UpdateProductPriceRequest extends FormRequest
{
    /** Policy-ul decide (`BulkOperationController::updateProductPrice()`, `ProductPolicy::bulkWrite()`). */
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
            'mode' => ['required', Rule::in(['percent', 'fixed'])],
            'direction' => ['required', Rule::in(['increase', 'decrease'])],
            'amount' => [
                'required',
                'numeric',
                'min:0.01',
                $this->input('mode') === 'percent' ? 'max:100' : 'max:1000000',
            ],
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

    /** @return array{mode: string, direction: string, amount: float} */
    public function pricePayload(): array
    {
        return [
            'mode' => $this->validated('mode'),
            'direction' => $this->validated('direction'),
            'amount' => (float) $this->validated('amount'),
        ];
    }
}
