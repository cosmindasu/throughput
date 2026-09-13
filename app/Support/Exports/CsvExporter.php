<?php

namespace App\Support\Exports;

use Illuminate\Database\Eloquent\Builder;

/**
 * Scrierea propriu-zisă a unui CSV dintr-o interogare `ExportableList`, comună căii
 * sincrone (`AccountController::export()`) și celei în coadă (`ExportListJob`) — un singur
 * loc care decide ordinea coloanelor și cum se scrie un rând, ca cele două căi să producă
 * byte-cu-byte același fișier pentru același filtru.
 *
 * `Builder::cursor()`, nu `get()`: rândurile se hidratează unul câte unul, niciodată toată
 * colecția în memorie deodată (§13.2 — „niciodată încărcare integrală în memorie").
 */
final class CsvExporter
{
    /**
     * @return resource flux poziționat la începutul conținutului (`rewind` deja aplicat)
     */
    public static function build(ExportableList $list, Builder $query)
    {
        $handle = fopen('php://temp', 'w+');

        fputcsv($handle, $list->exportHeaders());

        foreach ($query->cursor() as $row) {
            fputcsv($handle, $list->exportRow($row));
        }

        rewind($handle);

        return $handle;
    }

    public static function toString(ExportableList $list, Builder $query): string
    {
        $handle = self::build($list, $query);
        $content = stream_get_contents($handle);
        fclose($handle);

        return (string) $content;
    }
}
