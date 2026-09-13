<?php

namespace App\Http\Requests\Pipeline;

use Illuminate\Foundation\Http\FormRequest;

/**
 * FR-DEAL-02 — editare de etapă. Vezi docblock-ul `StoreStageRequest` pentru împărțirea
 * validare-de-formă (aici) / reguli-între-etape (`SaveStageAction`).
 */
class UpdateStageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('stage'));
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'probability' => ['nullable', 'integer', 'between:0,100'],
            'is_won' => ['sometimes', 'boolean'],
            'is_lost' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array{name: string, probability: ?int, is_won: bool, is_lost: bool}
     */
    public function stageData(): array
    {
        return [
            'name' => (string) $this->validated('name'),
            'probability' => $this->validated('probability'),
            'is_won' => $this->boolean('is_won'),
            'is_lost' => $this->boolean('is_lost'),
        ];
    }
}
