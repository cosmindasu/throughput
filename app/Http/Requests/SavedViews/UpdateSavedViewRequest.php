<?php

namespace App\Http\Requests\SavedViews;

use App\Models\SavedView;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Redenumire și/sau schimbare de vizibilitate — NU rescrierea filtrelor: „Save view" scrie
 * o vedere nouă din starea curentă a URL-ului (FR-VIEW-01); o vedere existentă nu se
 * resincronizează tăcut cu orice filtru aplicat mai târziu pe ecran.
 */
final class UpdateSavedViewRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var SavedView $savedView */
        $savedView = $this->route('savedView');

        if (! $this->user()->can('update', $savedView)) {
            return false;
        }

        if ($this->input('visibility') === SavedView::VISIBILITY_TEAM && $savedView->visibility !== SavedView::VISIBILITY_TEAM) {
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
            'name' => ['sometimes', 'string', 'max:120'],
            'visibility' => ['sometimes', Rule::in([SavedView::VISIBILITY_PRIVATE, SavedView::VISIBILITY_TEAM])],
        ];
    }
}
