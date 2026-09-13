<?php

namespace App\Http\Requests\Pipeline;

use App\Models\Stage;
use Illuminate\Foundation\Http\FormRequest;

/**
 * FR-DEAL-02 — creare de etapă. Validarea de formă (tip, interval) stă aici; regulile care
 * depind de CELELALTE etape ale pipeline-ului (nume unic, un singur Won/Lost) sunt în
 * `App\Actions\Pipeline\SaveStageAction`, care are nevoie de pipeline-ul rezolvat — informație
 * pe care acest request n-o are (nicio rută din acest pachet nu poartă `{pipeline}` în cale,
 * MVP fiind un singur pipeline implicit per tenant, specs.md §9.2).
 */
class StoreStageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Stage::class);
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
