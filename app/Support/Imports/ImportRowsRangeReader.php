<?php

namespace App\Support\Imports;

use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;

/**
 * Citește UN SINGUR interval de rânduri `[startRow, startRow + chunkSize)` din fișierul de
 * import — motorul din spatele `RunDryRunValidationJob`, care se re-dispecerizează singur,
 * un chunk per invocare de job (vezi docblock-ul acelui job pentru DE CE, nu doar CUM:
 * `config/horizon.php` fixează `timeout = 60` / `tries = 1` pe supervisorul unic — un singur
 * job care ar citi tot fișierul (`Excel::import()` sincron, cum sugerează literal §14.1) ar fi
 * omorât de Horizon la 60 de secunde pe orice fișier suficient de mare, FĂRĂ reîncercare).
 *
 * CSV/TXT: `fgetcsv()` simplu, cu liniile dinaintea `startRow` SĂRITE (nu citite/parsate ca
 * date) — memorie O(chunkSize), niciodată tot fișierul. XLSX: `IReadFilter` PhpSpreadsheet
 * restrâns la rândul de antet + intervalul cerut — la fel ca `ImportFileHeaders`/
 * `ImportFileRowCounter`, din același motiv (bugetul de memorie).
 *
 * `row_number` = indexul FIZIC din fișier (antetul e rândul 1, primul rând de date e 2) —
 * convenția „ce vede utilizatorul dacă deschide fișierul", cerută de US-IMP-01 („row 47,
 * column Price: …").
 */
final class ImportRowsRangeReader
{
    /**
     * @return array{headers: list<string>, rows: list<array{rowNumber: int, raw: array<string, mixed>}>, isLastChunk: bool}
     */
    public static function read(string $absolutePath, string $extension, int $startRow, int $chunkSize): array
    {
        return strtolower($extension) === 'xlsx'
            ? self::readXlsxRange($absolutePath, $startRow, $chunkSize)
            : self::readCsvRange($absolutePath, $startRow, $chunkSize);
    }

    /**
     * @return array{headers: list<string>, rows: list<array{rowNumber: int, raw: array<string, mixed>}>, isLastChunk: bool}
     */
    private static function readCsvRange(string $absolutePath, int $startRow, int $chunkSize): array
    {
        $stream = fopen($absolutePath, 'rb');

        if ($stream === false) {
            return ['headers' => [], 'rows' => [], 'isLastChunk' => true];
        }

        $rows = [];
        $isLastChunk = true;

        try {
            $headers = fgetcsv($stream);
            $headers = $headers === false ? [] : array_map(fn ($v) => trim((string) $v), $headers);

            $currentRow = 2;

            // Sare liniile dinaintea intervalului cerut — NU le parsează în celule, doar
            // avansează cursorul fișierului (`fgetcsv` tot trebuie apelat, dar rezultatul se
            // aruncă imediat, fără să treacă prin mapare/validare).
            while ($currentRow < $startRow) {
                if (fgetcsv($stream) === false) {
                    return ['headers' => $headers, 'rows' => [], 'isLastChunk' => true];
                }

                $currentRow++;
            }

            $collected = 0;

            while ($collected < $chunkSize) {
                $cells = fgetcsv($stream);

                if ($cells === false) {
                    break;
                }

                // Rând complet gol (ex: linia finală goală a unui CSV) — sărit, nu raportat
                // ca eroare, dar contorul de rând tot avansează (rămâne „ce vede utilizatorul").
                if (self::isBlankRow($cells)) {
                    $currentRow++;

                    continue;
                }

                $raw = [];

                foreach ($headers as $index => $header) {
                    $raw[$header] = $cells[$index] ?? null;
                }

                $rows[] = ['rowNumber' => $currentRow, 'raw' => $raw];
                $currentRow++;
                $collected++;
            }

            // Am ieșit din buclă înainte de a umple chunk-ul întreg → fișierul s-a terminat.
            // (Cazul de graniță „fișierul are EXACT `chunkSize` rânduri rămase" trimite o
            // dispecerizare în plus, inofensivă: chunk-ul următor găsește 0 rânduri și se
            // finalizează singur.)
            $isLastChunk = $collected < $chunkSize;
        } finally {
            fclose($stream);
        }

        return ['headers' => $headers, 'rows' => $rows, 'isLastChunk' => $isLastChunk];
    }

    /**
     * @param  list<mixed>  $cells
     */
    private static function isBlankRow(array $cells): bool
    {
        foreach ($cells as $cell) {
            if (trim((string) $cell) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array{headers: list<string>, rows: list<array{rowNumber: int, raw: array<string, mixed>}>, isLastChunk: bool}
     */
    private static function readXlsxRange(string $absolutePath, int $startRow, int $chunkSize): array
    {
        $endRow = $startRow + $chunkSize - 1;

        // Dimensiunea REALĂ a fișierului, ÎNAINTE de orice `IReadFilter` restrictiv —
        // `listWorksheetInfo()`, NU `getHighestRow()` pe foaia filtrată. Verificat direct, nu
        // presupus: cu filtrul de mai jos aplicat, `getHighestRow()` reflectă DOAR celulele
        // PĂSTRATE (antetul + chunk-ul curent), niciodată rândurile de DUPĂ chunk — deci
        // `isLastChunk` ar fi ieșit `true` la PRIMUL chunk, indiferent cât de mare era fișierul
        // real (bug găsit prin testare cu un fișier XLSX adevărat, nu prin citirea codului;
        // vezi și `ImportFileRowCounter`, aceeași presupunere greșită, corectată la fel).
        $trueHighestRow = (int) ((new Xlsx)->listWorksheetInfo($absolutePath)[0]['totalRows'] ?? 1);

        $reader = new Xlsx;
        $reader->setReadDataOnly(true);
        $reader->setReadFilter(new class($startRow, $endRow) implements IReadFilter
        {
            public function __construct(private readonly int $startRow, private readonly int $endRow) {}

            public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
            {
                return $row === 1 || ($row >= $this->startRow && $row <= $this->endRow);
            }
        });

        $spreadsheet = $reader->load($absolutePath);
        $sheet = $spreadsheet->getActiveSheet();
        $highestColumn = $sheet->getHighestColumn();

        $headers = array_map(
            fn ($v) => trim((string) $v),
            $sheet->rangeToArray('A1:'.$highestColumn.'1')[0] ?? [],
        );

        $rows = [];
        $lastRowToRead = min($endRow, $trueHighestRow);

        for ($rowNumber = $startRow; $rowNumber <= $lastRowToRead; $rowNumber++) {
            $cells = $sheet->rangeToArray('A'.$rowNumber.':'.$highestColumn.$rowNumber)[0] ?? [];

            if (self::isBlankRow($cells)) {
                continue;
            }

            $raw = [];

            foreach ($headers as $index => $header) {
                $raw[$header] = $cells[$index] ?? null;
            }

            $rows[] = ['rowNumber' => $rowNumber, 'raw' => $raw];
        }

        $spreadsheet->disconnectWorksheets();

        return ['headers' => $headers, 'rows' => $rows, 'isLastChunk' => $endRow >= $trueHighestRow];
    }
}
