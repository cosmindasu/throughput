<?php

namespace App\Support\Imports;

/**
 * Template CSV descărcabil per resursă (§14.1: „reduce fricțiunea" la mapare) — un singur
 * rând de antet, cu etichetele umane ale câmpurilor țintă, în ordinea declarată de resursă.
 *
 * `$field->label()` (FR-I18N-04): antetul se produce în locale-ul CERERII curente — cine
 * descarcă template-ul cu interfața în franceză primește antete franceze, nu engleza fixă
 * de dinainte de acest lot.
 */
final class ImportTemplateBuilder
{
    public static function toCsvString(ImportableResource $resource): string
    {
        $handle = fopen('php://temp', 'w+');

        fputcsv($handle, array_map(fn (ImportField $field) => $field->label(), $resource->fields()));

        rewind($handle);
        $content = (string) stream_get_contents($handle);
        fclose($handle);

        return $content;
    }
}
