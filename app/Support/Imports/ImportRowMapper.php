<?php

namespace App\Support\Imports;

/**
 * Aplică `column_mapping` (`{"csv_header": "target_field"}`, §14.2) pe un rând brut, keiat
 * pe antetul ORIGINAL — sursă unică pentru Proba uscată (`ImportDryRunChunkProcessor`) și
 * Commit (`CommitImportJob`), ca cele două să interpreteze identic aceeași mapare. O coloană
 * nemapată (`target_field === null`, ex. „error" la reimportul raportului) e ignorată tăcut.
 */
final class ImportRowMapper
{
    /**
     * @param  array<string, mixed>  $raw
     * @param  array<string, string|null>  $columnMapping
     * @return array<string, mixed>
     */
    public static function apply(array $raw, array $columnMapping): array
    {
        $mapped = [];

        foreach ($columnMapping as $header => $targetField) {
            if (! is_string($targetField) || $targetField === '') {
                continue;
            }

            $value = $raw[$header] ?? null;
            $mapped[$targetField] = is_string($value) && trim($value) === '' ? null : $value;
        }

        return $mapped;
    }
}
