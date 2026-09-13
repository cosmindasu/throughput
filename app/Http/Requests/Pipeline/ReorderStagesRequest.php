<?php

namespace App\Http\Requests\Pipeline;

use App\Models\Stage;
use Illuminate\Foundation\Http\FormRequest;

/**
 * FR-DEAL-02 — `PUT /{workspace}/pipeline/stages/order`. Corpul e lista ORDONATĂ completă de
 * id-uri de etape, nu o mutare punctuală — vezi `App\Actions\Pipeline\ReorderStagesAction`
 * pentru validarea „set complet, fără străine/duplicate/lipsă", care nu încape într-o regulă
 * `exists`/`distinct` simplă (are nevoie să compare cu setul EXACT al pipeline-ului).
 */
class ReorderStagesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('reorder', Stage::class);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'stage_ids' => ['required', 'array', 'min:1'],
            'stage_ids.*' => ['required', 'string'],
        ];
    }

    /**
     * @return list<string>
     */
    public function orderedStageIds(): array
    {
        /** @var list<string> $ids */
        $ids = $this->validated('stage_ids');

        return $ids;
    }
}
