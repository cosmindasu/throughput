<?php

namespace App\Support\Imports;

use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use RuntimeException;

/**
 * Citește DOAR antetul (rândul 1) unui fișier de import, pentru validarea de upload
 * (Pasul 1) și ecranul de mapare (Pasul 2) — niciodată tot fișierul: bugetul de memorie e
 * 250-400 MB (`.ai/rules/project.md`), iar un fișier la plafon (`import_max_rows` = 50.000)
 * nu are ce căuta încărcat integral doar ca să-i citim antetul.
 *
 * Ia o cale ABSOLUTĂ de sistem de fișiere, nu un disc Laravel — reutilizabilă atât pe
 * fișierul temporar al upload-ului HTTP (`UploadedFile::getRealPath()`, înainte de
 * persistare), cât și pe fișierul deja stocat (`Storage::disk('local')->path(...)`).
 *
 * CSV/TXT: un singur `fgetcsv()`, memorie O(1). XLSX: `IReadFilter` PhpSpreadsheet restrâns
 * la rândul 1 — pachetul (`maatwebsite/excel`) nu expune `WithLimit` în afara citirii pe
 * chunk-uri (verificat: `ChunkReader` e singurul consumator), deci un `Excel::toArray()` fără
 * `WithChunkReading` ar citi fișierul întreg doar ca să arunce restul.
 */
final class ImportFileHeaders
{
    /**
     * @return list<string>
     */
    public static function read(string $absolutePath, string $extension): array
    {
        return strtolower($extension) === 'xlsx'
            ? self::readXlsxHeaders($absolutePath)
            : self::readCsvHeaders($absolutePath);
    }

    /** @return list<string> */
    private static function readCsvHeaders(string $absolutePath): array
    {
        $stream = fopen($absolutePath, 'rb');

        if ($stream === false) {
            throw new RuntimeException("Import file not found: {$absolutePath}");
        }

        try {
            $headers = fgetcsv($stream);
        } finally {
            fclose($stream);
        }

        if ($headers === false) {
            return [];
        }

        return array_map(fn ($value) => trim((string) $value), $headers);
    }

    /** @return list<string> */
    private static function readXlsxHeaders(string $absolutePath): array
    {
        $reader = new Xlsx;
        $reader->setReadDataOnly(true);
        $reader->setReadFilter(new class implements IReadFilter
        {
            public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
            {
                return $row === 1;
            }
        });

        $spreadsheet = $reader->load($absolutePath);
        $sheet = $spreadsheet->getActiveSheet();
        $highestColumn = $sheet->getHighestColumn();
        $row = $sheet->rangeToArray('A1:'.$highestColumn.'1')[0] ?? [];

        $spreadsheet->disconnectWorksheets();

        return array_values(array_filter(
            array_map(fn ($value) => trim((string) $value), $row),
            fn (string $value) => $value !== '',
        ));
    }
}
