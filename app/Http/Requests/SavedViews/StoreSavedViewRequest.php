<?php

namespace App\Http\Requests\SavedViews;

use App\Models\SavedView;
use App\Support\SavedViews\SavedViewResourceType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * FR-VIEW-01 — salvarea pornește din starea curentă a URL-ului. `filter`/`sort` călătoresc
 * EXACT în forma `ListQuery::toArray()` (§15.2), dar NU sunt de încredere ca atare: controller-ul
 * le trece din nou prin `ResourceList::fromState()` — aceeași definiție de listă care validează
 * cererile HTTP normale — înainte de a le scrie. O cheie necunoscută sau o valoare respinsă de
 * `AccountList::accepts()`/`DealList::accepts()` dispare tăcut, exact ca la deschiderea unui
 * link vechi (`ListQuery` nu aruncă 422 pentru asta, §15.2).
 */
final class StoreSavedViewRequest extends FormRequest
{
    public function authorize(): bool
    {
        if (! $this->user()->can('create', SavedView::class)) {
            return false;
        }

        if ($this->input('visibility') === SavedView::VISIBILITY_TEAM) {
            return $this->user()->can('createTeam', SavedView::class);
        }

        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'resource_type' => ['required', 'string', Rule::in(SavedViewResourceType::supported())],
            'name' => ['required', 'string', 'max:120'],
            'visibility' => ['required', Rule::in([SavedView::VISIBILITY_PRIVATE, SavedView::VISIBILITY_TEAM])],
            'filter' => ['sometimes', 'array'],
            'filter.*' => ['nullable', 'string', 'max:100'],
            'sort' => ['sometimes', 'nullable', 'string', 'max:60'],
        ];
    }
}
