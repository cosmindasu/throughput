<?php

namespace App\Support\Imports;

use App\Models\ImportRow;
use Illuminate\Support\Facades\Validator as ValidatorFacade;
use Illuminate\Validation\Validator;

/**
 * Validarea + scrierea `import_rows` pentru UN SINGUR chunk deja citit din fișier — apelat
 * din `RunDryRunValidationJob`, ÎNTR-O tranzacție de tenant deja deschisă (job middleware).
 *
 * Duplicatele ÎN FIȘIER (același SKU/email la două rânduri din ACELAȘI import) NU se
 * detectează aici — un chunk nu vede rândurile deja scrise de chunk-urile ANTERIOARE fără o
 * interogare suplimentară, iar rândurile ACELUIAȘI chunk sunt oricum verificate corect doar
 * dacă chunk-urile se procesează strict secvențial (adevărat aici, dar fragil ca invariantă
 * de bază). Detectarea se face într-o singură trecere FINALĂ, după ultimul chunk —
 * `ImportDryRunFinalizer`, care vede tot fișierul deja scris în `import_rows` și nu depinde
 * de ordinea de execuție a joburilor. Aici se verifică DOAR: (1) formatul/obligativitatea
 * câmpurilor, (2) duplicatul FAȚĂ DE ENTITĂȚI DEJA EXISTENTE în bază — stabil indiferent de
 * ordine, fiindcă acele entități nu se schimbă în timpul probei uscate.
 */
final class ImportDryRunChunkProcessor
{
    public function __construct(
        private readonly ImportableResource $resource,
        private readonly array $columnMapping,
    ) {}

    /**
     * @param  list<array{rowNumber: int, raw: array<string, mixed>}>  $rows
     * @return array{validCount: int, errorCount: int}
     */
    public function process(string $importId, array $rows): array
    {
        $prepared = [];

        foreach ($rows as $entry) {
            $mapped = ImportRowMapper::apply($entry['raw'], $this->columnMapping);
            $errors = $this->validateFields($mapped);

            $prepared[] = [
                'rowNumber' => $entry['rowNumber'],
                'raw' => $entry['raw'],
                'errors' => $errors,
                'signature' => $this->resource->duplicateSignature($mapped),
            ];
        }

        $candidatesByField = [];

        foreach ($prepared as $entry) {
            if ($entry['errors'] === [] && $entry['signature'] !== null) {
                $candidatesByField[$entry['signature']['field']][] = $entry['signature']['value'];
            }
        }

        $existingByField = [];

        foreach ($candidatesByField as $field => $values) {
            $existingByField[$field] = array_flip(
                $this->resource->existingValues($field, array_values(array_unique($values)))
            );
        }

        $validCount = 0;
        $errorCount = 0;

        foreach ($prepared as $entry) {
            $errors = $entry['errors'];
            $signature = $entry['signature'];

            if ($errors === [] && $signature !== null && isset($existingByField[$signature['field']][$signature['value']])) {
                // I18N-07, FR-I18N-04 — mutat din literal englez direct într-o cheie
                // (`lang/{en,fr}/imports.php`). ATENȚIE la locale: acest cod rulează în
                // `RunDryRunValidationJob` (job de tenant, `ApplyTenantContextToJob`), care
                // NU apelează `App::setLocale()` — spre deosebire de tiparul stabilit în
                // `App\Jobs\Reports\DeliverReportJob`/`ExportTenantEntityJob` (ADR-022,
                // FR-I18N-05, `.ai/rules/tenancy.md:123-138`). Mesajul de-aici moștenește deci
                // limba pe care `App::currentLocale()` o are ÎNTÂMPLĂTOR pe worker-ul de
                // coadă în momentul rulării jobului, nu neapărat limba utilizatorului care a
                // pornit importul — același risc de scurgere între joburi documentat în
                // `tests/Feature/I18n/JobLocaleLeakTest.php`, dar nereparat aici (nu face
                // parte din felia asta; jobul nu a fost atins).
                $errors[] = [
                    'field' => $signature['field'],
                    'message' => __('imports.validation.duplicate_value'),
                ];
            }

            $status = $errors === [] ? ImportRow::STATUS_VALID : ImportRow::STATUS_INVALID;

            ImportRow::create([
                'import_id' => $importId,
                'row_number' => $entry['rowNumber'],
                'raw_data' => $entry['raw'],
                'status' => $status,
                'errors' => $errors === [] ? null : array_values($errors),
            ]);

            $status === ImportRow::STATUS_VALID ? $validCount++ : $errorCount++;
        }

        return ['validCount' => $validCount, 'errorCount' => $errorCount];
    }

    /**
     * @param  array<string, mixed>  $mapped
     * @return list<array{field: string, message: string}>
     */
    private function validateFields(array $mapped): array
    {
        $rules = [];
        $attributes = [];
        $data = [];

        foreach ($this->resource->fields() as $field) {
            $data[$field->key] = $mapped[$field->key] ?? null;
            $rules[$field->key] = $field->rules;
            $attributes[$field->key] = $field->label();
        }

        $validator = ValidatorFacade::make($data, $rules, [], $attributes);

        if ($validator->passes()) {
            return [];
        }

        return $this->flattenErrors($validator);
    }

    /**
     * @return list<array{field: string, message: string}>
     */
    private function flattenErrors(Validator $validator): array
    {
        $errors = [];

        foreach ($validator->errors()->messages() as $field => $messages) {
            foreach ($messages as $message) {
                $errors[] = ['field' => $field, 'message' => $message];
            }
        }

        return $errors;
    }
}
