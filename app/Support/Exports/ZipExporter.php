<?php

namespace App\Support\Exports;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

/**
 * FR-BILL-03 — arhiva ZIP a fișierelor deja generate pentru rândurile unei liste
 * (`ArchivableList`). Al treilea format al ACELUIAȘI mecanism (§13.2), lângă `CsvExporter`
 * și `PdfExporter`: aceeași operație `bulk_operations`, același `ExportListJob`, aceeași
 * pagină de status și același link de descărcare cu expirare. Niciun mecanism nou.
 *
 * MEMORIE (bugetul §3, 250-400 MB la vârf): `ZipArchive::addFile()` NU citește fișierul la
 * apel — îl deschide abia la `close()` și îl comprimă în flux. Deci vârful nu crește cu
 * numărul de PDF-uri, spre deosebire de `PdfExporter` (unde DomPDF materializează toate
 * rândurile în memorie — de aici plafonul de 250). În memorie rămân doar numele și căile,
 * câteva zeci de octeți per rând. `addFromString()` ar fi fost exact greșeala opusă: ține
 * conținutul în RAM până la `close()`.
 *
 * DISC: arhiva se construiește într-un fișier temporar și abia apoi se mută pe discul
 * `local`, prin `putFileAs`. `ZipArchive` are nevoie de o cale reală de sistem de fișiere,
 * iar `Storage::path()` o dă doar pentru driverul `local` — trecerea prin temp păstrează
 * codul corect dacă discul devine vreodată altceva, și nu lasă o arhivă pe jumătate scrisă
 * la calea finală dacă jobul moare la mijloc (OOM real pe VPS-ul comun).
 */
final class ZipExporter
{
    /**
     * Indexul lizibil al arhivei. Există ÎNTOTDEAUNA, din două motive: (1) o arhivă în care
     * lipsesc PDF-uri trebuie să spună CARE și DE CE, altfel „12 facturi, 10 fișiere" e un
     * mister; (2) `ZipArchive::close()` eșuează pe o arhivă fără nicio intrare, iar un
     * filtru care nu potrivește nimic e un caz normal, nu o eroare.
     */
    private const INDEX_ENTRY = 'contents.txt';

    public static function save(
        ExportableList&ArchivableList $list,
        Builder $query,
        string $path,
        string $workspaceName,
    ): void {
        $temporaryPath = tempnam(sys_get_temp_dir(), 'throughput-export-').'.zip';

        $archive = new ZipArchive;

        if ($archive->open($temporaryPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create the export archive.');
        }

        $matched = 0;
        $included = 0;
        /** @var list<string> $missing */
        $missing = [];
        /** @var array<string, int> $usedNames */
        $usedNames = [];

        try {
            ExportQueryChunker::each($query, function ($rows) use (
                $archive, $list, &$matched, &$included, &$missing, &$usedNames
            ): void {
                foreach ($rows as $row) {
                    $matched++;

                    /** @var Model $row */
                    $relativePath = $list->archiveEntryPath($row);
                    $absolutePath = $relativePath !== null ? Storage::disk('local')->path($relativePath) : null;

                    // `is_file()` pe lângă `archiveEntryPath()`: coloana poate arăta spre un
                    // fișier șters de retenție (§13.2 pct. 9) sau de reset-ul demo-ului.
                    // Fără verificare, `addFile()` ar reuși acum și `close()` ar eșua la
                    // final — pentru TOATĂ arhiva, din cauza unui singur rând.
                    if ($absolutePath === null || ! is_file($absolutePath)) {
                        $missing[] = $list->archiveEntryMissingReason($row);

                        continue;
                    }

                    $archive->addFile($absolutePath, self::uniqueName($list->archiveEntryName($row), $usedNames));
                    $included++;
                }
            });

            $archive->addFromString(
                self::INDEX_ENTRY,
                self::index($workspaceName, $matched, $included, $missing),
            );

            if ($archive->close() !== true) {
                throw new RuntimeException('Could not finish writing the export archive.');
            }

            $handle = fopen($temporaryPath, 'r');

            if ($handle === false) {
                throw new RuntimeException('Could not read back the export archive.');
            }

            Storage::disk('local')->writeStream($path, $handle);

            if (is_resource($handle)) {
                fclose($handle);
            }
        } finally {
            // `close()` a reușit sau nu; `unlink` e sigur în ambele cazuri, iar un temp rămas
            // în urmă pe un VPS partajat e exact genul de gunoi care se descoperă târziu.
            if (is_file($temporaryPath)) {
                @unlink($temporaryPath);
            }
        }
    }

    /**
     * Numerele de factură sunt unice per tenant, deci coliziunile sunt teoretice — dar o
     * arhivă cu două intrări identice pierde tăcut un fișier la dezarhivare, iar „tăcut" e
     * exact ce nu vrem. Sufixul e determinist, nu aleatoriu.
     *
     * @param  array<string, int>  $usedNames
     */
    private static function uniqueName(string $name, array &$usedNames): string
    {
        $safe = preg_replace('/[^A-Za-z0-9._-]+/', '-', $name) ?? $name;
        $safe = trim($safe, '-') ?: 'file';

        if (! array_key_exists($safe, $usedNames)) {
            $usedNames[$safe] = 1;

            return $safe;
        }

        $extension = pathinfo($safe, PATHINFO_EXTENSION);
        $base = $extension === '' ? $safe : substr($safe, 0, -(strlen($extension) + 1));

        return $base.'-'.(++$usedNames[$safe]).($extension === '' ? '' : '.'.$extension);
    }

    /**
     * @param  list<string>  $missing
     */
    private static function index(string $workspaceName, int $matched, int $included, array $missing): string
    {
        $lines = [
            'Invoice export — '.$workspaceName,
            'Generated at '.now()->toIso8601String(),
            '',
            'Invoices matched by the filter: '.$matched,
            'PDF files included: '.$included,
            'Missing: '.count($missing),
        ];

        if ($missing !== []) {
            $lines[] = '';
            $lines[] = 'The following invoices have no PDF in this archive:';

            foreach ($missing as $reason) {
                $lines[] = '  - '.$reason;
            }
        }

        return implode("\n", $lines)."\n";
    }
}
