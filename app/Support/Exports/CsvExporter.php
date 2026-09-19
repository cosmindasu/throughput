<?php

namespace App\Support\Exports;

use Illuminate\Database\Eloquent\Builder;

/**
 * Scrierea propriu-zisă a unui CSV dintr-o interogare `ExportableList`, comună căii
 * sincrone (`AccountController::export()`) și celei în coadă (`ExportListJob`) — un singur
 * loc care decide ordinea coloanelor și cum se scrie un rând, ca cele două căi să producă
 * byte-cu-byte același fișier pentru același filtru.
 *
 * `ExportQueryChunker::each()`, NU `Builder::cursor()` (code review, scenariile k6) —
 * `cursor()` NU apelează `eagerLoadRelations()`, deci `->with(['account:id,name', ...])`
 * era ignorat tăcut și fiecare rând declanșa o interogare lazy per relație: 5.817
 * interogări măsurate pentru un export de 2.908 rânduri, în loc de ~18. Chunker-ul
 * paginează pe cursor (keyset, nu OFFSET) — memoria rămâne mărginită la un chunk, ca la
 * `cursor()`, dar eager-load-ul se aplică o dată per chunk.
 *
 * P1-001 — injecție de formule CSV (OWASP CSV/Formula Injection): orice celulă text care
 * începe cu `=`, `+`, `-`, `@`, tab sau retur de car se deschide ca formulă în Excel/Sheets
 * la export. Remediată aici, o singură dată, pentru toate exporturile prezente și
 * viitoare — nu în fiecare `exportRow()`.
 */
final class CsvExporter
{
    /**
     * Prefixele RFC 4180 / OWASP care pornesc o formulă când celula se deschide într-un
     * spreadsheet. Tab (`\t`) și CR (`\r`) sunt incluse pentru că Excel le tratează la fel
     * ca `=` la începutul unei celule.
     */
    private const DANGEROUS_PREFIXES = ['=', '+', '-', '@', "\t", "\r"];

    /**
     * @return resource flux poziționat la începutul conținutului (`rewind` deja aplicat)
     */
    public static function build(ExportableList $list, Builder $query)
    {
        $handle = fopen('php://temp', 'w+');

        fputcsv($handle, $list->exportHeaders());

        ExportQueryChunker::each($query, function ($rows) use ($handle, $list): void {
            foreach ($rows as $row) {
                fputcsv($handle, self::sanitizeRow($list->exportRow($row)));
            }
        });

        rewind($handle);

        return $handle;
    }

    /**
     * @param  list<string|int|float|null>  $row
     * @return list<string|int|float|null>
     */
    private static function sanitizeRow(array $row): array
    {
        return array_map(
            fn (string|int|float|null $value) => is_string($value) ? self::escapeFormula($value) : $value,
            $row,
        );
    }

    /**
     * Prefixează cu un apostrof orice valoare care ar porni o formulă — Excel și Sheets
     * ambele afișează apostroful ca text literal, niciodată ca parte din valoare.
     */
    private static function escapeFormula(string $value): string
    {
        foreach (self::DANGEROUS_PREFIXES as $prefix) {
            if (str_starts_with($value, $prefix)) {
                return "'".$value;
            }
        }

        return $value;
    }

    public static function toString(ExportableList $list, Builder $query): string
    {
        $handle = self::build($list, $query);
        $content = stream_get_contents($handle);
        fclose($handle);

        return (string) $content;
    }
}
