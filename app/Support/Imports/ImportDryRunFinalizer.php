<?php

namespace App\Support\Imports;

use App\Models\Import;
use App\Models\ImportRow;
use Illuminate\Support\Facades\DB;

/**
 * Ultima trecere a probei uscate (FR-IMP-01): duplicatele ÎN FIȘIER, între rânduri scrise de
 * chunk-uri DIFERITE (deci job-uri DIFERITE — vezi `ImportDryRunChunkProcessor`, care nu le
 * poate vedea). Rulează O SINGURĂ DATĂ, după ultimul chunk (`RunDryRunValidationJob`, când
 * `isLastChunk` e adevărat), pe TOATE rândurile `valid` de până acum, ordonate implicit după
 * `id` (ULID monoton la inserare, ÎN ORDINEA fișierului — echivalent cu `row_number`, fără
 * conflictul „`orderBy` concurent cu `chunkById`" semnalat în `ImportErrorReportBuilder`).
 *
 * Prima apariție a unei valori rămâne `valid`; oricare apariție ULTERIOARĂ devine `invalid`,
 * cu eroarea adăugată la cele existente (dacă rândul avea deja alte erori de format,
 * imposibil aici — doar rândurile `valid` intră în această trecere).
 */
final class ImportDryRunFinalizer
{
    private const CHUNK_SIZE = 500;

    public static function run(string $importId, ImportableResource $resource, array $columnMapping): void
    {
        /** @var array<string, true> */
        $seen = [];
        $newlyInvalid = 0;

        ImportRow::query()
            ->where('import_id', $importId)
            ->where('status', ImportRow::STATUS_VALID)
            ->chunkById(self::CHUNK_SIZE, function ($rows) use (&$seen, &$newlyInvalid, $resource, $columnMapping): void {
                foreach ($rows as $row) {
                    /** @var ImportRow $row */
                    $mapped = ImportRowMapper::apply((array) $row->raw_data, $columnMapping);
                    $signature = $resource->duplicateSignature($mapped);

                    if ($signature === null) {
                        continue;
                    }

                    $key = $signature['field'].'|'.$signature['value'];

                    if (isset($seen[$key])) {
                        $row->update([
                            'status' => ImportRow::STATUS_INVALID,
                            'errors' => [[
                                'field' => $signature['field'],
                                'message' => 'Duplicate value — already used by an earlier row in this file.',
                            ]],
                        ]);
                        $newlyInvalid++;
                    } else {
                        $seen[$key] = true;
                    }
                }
            });

        if ($newlyInvalid > 0) {
            Import::query()->whereKey($importId)->update([
                'valid_rows' => DB::raw('greatest(coalesce(valid_rows, 0) - '.$newlyInvalid.', 0)'),
                'error_rows' => DB::raw('coalesce(error_rows, 0) + '.$newlyInvalid),
            ]);
        }
    }
}
