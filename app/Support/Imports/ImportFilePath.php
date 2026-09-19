<?php

namespace App\Support\Imports;

use App\Models\Import;

/**
 * Calea de disc a fișierului încărcat — DERIVATĂ, nu stocată pe model: `imports` (migrată
 * deja în Faza 1) n-are o coloană `file_path`, iar acest lot nu modifică migrația. Calea e
 * deterministă din `tenant_id` + `id` + extensia din `original_filename` (păstrată exact la
 * upload) — aceeași funcție scrie ȘI citește, deci nu poate diverge.
 *
 * Disc `local` (privat), ca la `ExportListJob` — fișierele de import conțin date de business
 * brute, niciodată pe un disc public.
 */
final class ImportFilePath
{
    public const DISK = 'local';

    public static function extension(Import $import): string
    {
        $extension = strtolower((string) pathinfo($import->original_filename, PATHINFO_EXTENSION));

        return $extension !== '' ? $extension : 'csv';
    }

    public static function for(Import $import): string
    {
        return "imports/{$import->tenant_id}/{$import->getKey()}.".self::extension($import);
    }
}
