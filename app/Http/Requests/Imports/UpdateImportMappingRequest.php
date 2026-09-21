<?php

namespace App\Http\Requests\Imports;

use App\Models\Import;
use App\Support\Imports\ImportableResources;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Pasul 2 — Mapare (§14.1, US-IMP-02). `mapping`: `{"csv_header": "target_field"|null}`,
 * exact forma din `imports.column_mapping` (§14.2) — nicio transformare între cerere și
 * coloană. Orice câmp OBLIGATORIU al resursei trebuie mapat pe o coloană, altfel dry-run-ul
 * n-ar avea ce valida pe el.
 */
final class UpdateImportMappingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Import::class);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'mapping' => ['required', 'array'],
            'mapping.*' => ['nullable', 'string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /** @var Import $import */
            $import = $this->route('import');
            $resource = ImportableResources::resolve($import->resource_type);
            $mappedFields = array_values(array_filter((array) $this->input('mapping')));

            foreach ($resource->fields() as $field) {
                if ($field->required && ! in_array($field->key, $mappedFields, true)) {
                    // Fraza ÎNTREAGĂ trece prin catalog, nu doar cele două etichete
                    // interpolate (FR-I18N-04) — vezi nota de la cheie în `lang/en/imports.php`.
                    $validator->errors()->add('mapping', __('imports.validation.required_field_unmapped', [
                        'field' => $field->label(),
                        'resource' => $resource->label(),
                    ]));
                }
            }
        });
    }
}
