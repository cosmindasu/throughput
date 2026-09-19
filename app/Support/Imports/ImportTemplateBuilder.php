<?php

namespace App\Support\Imports;

/**
 * Template CSV descărcabil per resursă (§14.1: „reduce fricțiunea" la mapare) — un singur
 * rând de antet, cu etichetele umane ale câmpurilor țintă, în ordinea declarată de resursă.
 */
final class ImportTemplateBuilder
{
    public static function toCsvString(ImportableResource $resource): string
    {
        $handle = fopen('php://temp', 'w+');

        fputcsv($handle, array_map(fn (ImportField $field) => $field->label, $resource->fields()));

        rewind($handle);
        $content = (string) stream_get_contents($handle);
        fclose($handle);

        return $content;
    }
}
