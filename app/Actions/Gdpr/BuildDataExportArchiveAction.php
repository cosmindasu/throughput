<?php

namespace App\Actions\Gdpr;

use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

/**
 * Pasul final al exportului GDPR: scrie `manifest.json` peste intrările lăsate de fiecare
 * job de entitate, împachetează tot într-un ZIP și șterge directorul de lucru.
 *
 * **Nu ține nimic în memorie**: `ZipArchive::addFile()` primește căi, iar compresia se
 * întâmplă la `close()`, citind din fișiere — spre deosebire de `addFromString()`, care ar
 * fi cerut încărcarea fiecărui JSON întreg în memorie, adică exact ce a evitat scrierea în
 * flux din `WriteEntityExportAction`.
 *
 * Rulează ÎNTRE cele două tranzacții scurte ale lui `App\Jobs\Gdpr\FinalizeDataExportJob`
 * (ADR-013/ADR-014 pct. 5): compresia a zeci de MB nu are voie să țină o tranzacție
 * Postgres deschisă pe un container cu `max_connections=30`.
 */
final class BuildDataExportArchiveAction
{
    /**
     * @param  array<string, mixed>  $manifest
     * @return string calea relativă a arhivei pe discul `local`
     */
    public function execute(string $tenantId, string $requestId, array $manifest): string
    {
        $disk = Storage::disk(DataExportPaths::DISK);
        $folder = DataExportPaths::workFolder($tenantId, $requestId);

        $disk->put(
            "{$folder}/manifest.json",
            (string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        );

        $archivePath = DataExportPaths::archive($tenantId, $requestId);
        $disk->makeDirectory(DataExportPaths::tenantFolder($tenantId));

        $zip = new ZipArchive;
        $opened = $zip->open($disk->path($archivePath), ZipArchive::CREATE | ZipArchive::OVERWRITE);

        if ($opened !== true) {
            throw new RuntimeException("Could not create the export archive (ZipArchive error {$opened}).");
        }

        $zip->addFile($disk->path("{$folder}/manifest.json"), 'manifest.json');

        foreach ($this->payloadFiles($folder) as $file) {
            $zip->addFile($disk->path($file), basename($file));
        }

        if (! $zip->close()) {
            throw new RuntimeException('Could not finish writing the export archive.');
        }

        // Părțile nu mai au niciun consumator: linkul livrat e ZIP-ul, iar o repornire a
        // cererii rescrie oricum fiecare fișier de la zero.
        $disk->deleteDirectory($folder);

        return $archivePath;
    }

    /**
     * Fișierele de date, în ordine stabilă. `*.meta.json` sunt însemnările interne din care
     * s-a compus manifestul — nu intră în arhivă; `manifest.json` e adăugat separat, primul.
     *
     * @return list<string>
     */
    private function payloadFiles(string $folder): array
    {
        $files = array_values(array_filter(
            Storage::disk(DataExportPaths::DISK)->files($folder),
            static fn (string $file): bool => ! str_ends_with($file, '.meta.json')
                && basename($file) !== 'manifest.json',
        ));

        sort($files);

        return $files;
    }
}
