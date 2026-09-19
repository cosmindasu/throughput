<?php

namespace App\Support\Imports;

use App\Models\Import;
use App\Models\ImportRow;
use Illuminate\Support\Facades\Storage;

/**
 * Raportul de erori (§14.1 pct. 5, US-IMP-01): CSV cu DOAR rândurile eșuate, coloanele
 * ORIGINALE (exact antetele din fișierul încărcat, în ordinea lor) + o coloană `error` cu
 * mesajele concatenate. Reimportabil: la reîncărcare, `error` nu se potrivește cu niciun
 * alias de câmp (`ColumnMappingSuggester`), deci rămâne nemapat și e ignorat automat — fără
 * niciun cod special de „ignoră coloana asta".
 *
 * Ordinea antetelor se recitește din FIȘIERUL STOCAT (`ImportFileHeaders`), NU din
 * `array_keys($import->column_mapping)` — găsit la testare: PostgreSQL `jsonb` NU păstrează
 * ordinea cheilor unui obiect la scriere (le reordonează după lungime, apoi lexicografic),
 * deci `column_mapping` citit înapoi din bază avea coloanele amestecate („SKU, Cost, Price,
 * Product Name" în loc de ordinea din fișier). Fișierul original rămâne pe disc (nu se
 * șterge niciodată), deci antetul lui e sursa de adevăr STABILĂ pentru ordine — la fel ca la
 * mapare (Pasul 2).
 *
 * `import_rows.raw_data` e cheiat pe antetele ORIGINALE (heading row formatter dezactivat în
 * `ImportRowsRangeReader` nu se aplică aici — citirea CSV brută nu trece prin Maatwebsite),
 * deci scrierea de aici e byte-cu-byte ce a fost citit, nicio reconstrucție aproximativă.
 *
 * Protecție OWASP CSV/Formula Injection (P1-001, `App\Support\Exports\CsvExporter`) —
 * `raw_data` vine din fișierul UNTRUSTED al utilizatorului, deci exact genul de conținut
 * care ar porni o formulă la redeschidere în Excel/Sheets.
 */
final class ImportErrorReportBuilder
{
    private const DANGEROUS_PREFIXES = ['=', '+', '-', '@', "\t", "\r"];

    private const CHUNK_SIZE = 500;

    /**
     * @return resource flux poziționat la începutul conținutului (`rewind` deja aplicat)
     */
    public static function build(Import $import): mixed
    {
        $absolutePath = Storage::disk(ImportFilePath::DISK)->path(ImportFilePath::for($import));
        $headers = ImportFileHeaders::read($absolutePath, ImportFilePath::extension($import));
        $handle = fopen('php://temp', 'w+');

        fputcsv($handle, [...$headers, 'error']);

        // FĂRĂ `orderBy('row_number')` alături de `chunkById()`: cursorul lui `chunkById()`
        // e pe `id` (WHERE id > ultimul), deci un ORDER BY concurent pe altă coloană ar putea
        // sări/dubla rânduri între pagini. ULID-urile sunt monoton crescătoare la inserare, iar
        // rândurile se inserează ÎN ORDINEA fișierului (`ImportRowsFileReader`), deci ordinea
        // pe `id` coincide practic cu ordinea pe `row_number`.
        ImportRow::query()
            ->where('import_id', $import->getKey())
            ->where('status', ImportRow::STATUS_INVALID)
            ->chunkById(self::CHUNK_SIZE, function ($rows) use ($handle, $headers): void {
                foreach ($rows as $row) {
                    /** @var ImportRow $row */
                    $raw = (array) $row->raw_data;
                    $line = array_map(fn (string $header) => self::escapeFormula((string) ($raw[$header] ?? '')), $headers);
                    $line[] = self::escapeFormula(self::formatErrors($row->errors ?? []));

                    fputcsv($handle, $line);
                }
            });

        rewind($handle);

        return $handle;
    }

    public static function toString(Import $import): string
    {
        $handle = self::build($import);
        $content = (string) stream_get_contents($handle);
        fclose($handle);

        return $content;
    }

    /**
     * @param  list<array{field: string, message: string}>  $errors
     */
    private static function formatErrors(array $errors): string
    {
        return implode('; ', array_map(
            fn (array $error) => isset($error['field']) ? "{$error['field']}: {$error['message']}" : (string) ($error['message'] ?? ''),
            $errors,
        ));
    }

    private static function escapeFormula(string $value): string
    {
        foreach (self::DANGEROUS_PREFIXES as $prefix) {
            if (str_starts_with($value, $prefix)) {
                return "'".$value;
            }
        }

        return $value;
    }
}
