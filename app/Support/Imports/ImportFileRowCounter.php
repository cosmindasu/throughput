<?php

namespace App\Support\Imports;

use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use RuntimeException;

/**
 * Numărul de rânduri DE DATE (fără antet) — FR-IMP-02, verificat la upload, în cererea HTTP.
 * Ia o cale ABSOLUTĂ de sistem de fișiere — vezi docblock-ul `ImportFileHeaders`, motivul e
 * identic (reutilizare pe fișierul temporar ȘI pe cel stocat).
 *
 * Rămâne o operație ieftină, nu o parsare completă (ADR-013 interzice munca grea în cerere):
 * CSV — numărare de linii (`fgets` în buclă, memorie O(1), fără `fgetcsv`/validare per câmp);
 * XLSX — `Xlsx::listWorksheetInfo()`, NU `getHighestRow()` cu un `IReadFilter` care respinge
 * toate celulele (prima încercare, respinsă la testare cu un fișier real): un `IReadFilter`
 * restrictiv face ca `getHighestRow()` să reflecte DOAR celulele PĂSTRATE, nu dimensiunea
 * reală a fișierului — cu un filtru „respinge tot", un fișier de 4 rânduri raporta
 * `getHighestRow() === 1`, deci FIȘIERUL ÎNTREG ieșea cu 0 rânduri de date (verificat direct,
 * nu presupus — vezi și `ImportRowsRangeReader`, care avea aceeași presupunere greșită pe
 * calea de citire pe chunk-uri). `listWorksheetInfo()` e metoda dedicată a PhpSpreadsheet
 * pentru exact acest scop: dimensiunea foii, citită dintr-un scan XML ieftin, fără
 * instanțierea vreunei celule.
 */
final class ImportFileRowCounter
{
    public static function count(string $absolutePath, string $extension): int
    {
        return strtolower($extension) === 'xlsx'
            ? self::countXlsxRows($absolutePath)
            : self::countCsvRows($absolutePath);
    }

    private static function countCsvRows(string $absolutePath): int
    {
        $stream = fopen($absolutePath, 'rb');

        if ($stream === false) {
            throw new RuntimeException("Import file not found: {$absolutePath}");
        }

        $lines = 0;

        try {
            while (($line = fgets($stream)) !== false) {
                if (trim($line) !== '') {
                    $lines++;
                }
            }
        } finally {
            fclose($stream);
        }

        // Antetul e rândul 1 — rândurile DE DATE sunt tot restul (minim 0, niciodată negativ
        // pentru un fișier gol).
        return max(0, $lines - 1);
    }

    private static function countXlsxRows(string $absolutePath): int
    {
        $sheets = (new Xlsx)->listWorksheetInfo($absolutePath);
        $highestRow = $sheets[0]['totalRows'] ?? 0;

        return max(0, $highestRow - 1);
    }
}
