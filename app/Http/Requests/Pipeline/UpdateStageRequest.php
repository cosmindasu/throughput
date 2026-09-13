<?php

namespace App\Http\Requests\Pipeline;

use App\Models\Stage;
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
        /** @var Stage $stage */
        $stage = $this->route('stage');

        // P2-001: un PATCH e parțial de drept — o cheie absentă din payload NU înseamnă
        // "setează la false/null", înseamnă "nu se schimbă". `boolean()`/`validated()` fără
        // `has()` întorc `false`/`null` pentru o cheie lipsă, deci un PATCH cu doar `{name}`
        // ar fi dezactivat tăcut Won/Lost și ar fi șters probabilitatea etapei.
        return [
            'name' => (string) $this->validated('name'),
            'probability' => $this->has('probability') ? $this->validated('probability') : $stage->probability,
            'is_won' => $this->has('is_won') ? $this->boolean('is_won') : $stage->is_won,
            'is_lost' => $this->has('is_lost') ? $this->boolean('is_lost') : $stage->is_lost,
        ];
    }
}
