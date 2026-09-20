<?php

namespace App\Actions\Gdpr;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Scrie fișierele UNEI entități din arhiva GDPR: `{entity}.json`, opțional `{entity}.csv`,
 * plus `{entity}.meta.json` (numărătoarea și lista de câmpuri, citite la final ca să
 * compună `manifest.json` fără să redeschidă nimic).
 *
 * **Scriere în FLUX, obligatoriu** (`.ai/rules/project.md`, buget 250-400 MB): rândurile
 * se parcurg cu `chunkById` (keyset pe cheia primară ULID, niciodată OFFSET) și fiecare
 * rând se serializează și se scrie imediat. Un export care materializează tot tenantul
 * într-un array înainte de `json_encode` e exact greșeala pe care VPS-ul o plătește cu un
 * OOM — tenantul vitrină are ~30.000 de comenzi (specs.md §21.1), iar jurnalul de
 * activitate e mai mare decât ele.
 *
 * JSON-ul e un array valid scris pe bucăți: `[`, apoi un obiect pe linie separat prin
 * virgulă, apoi `]`. Un rând pe linie e și o alegere practică — arhiva se poate inspecta
 * cu `head` fără să se citească întreg fișierul în memorie.
 */
final class WriteEntityExportAction
{
    /**
     * 500 de rânduri, ca `bulk_chunk_size`, dar constantă proprie, nu aceeași cheie de
     * config: un rând de export ține modelul PLUS relațiile imbricate (liniile unei
     * comenzi), deci nu se poate regla împreună cu un chunk de operație în masă, care ține
     * doar un id.
     */
    private const CHUNK = 500;

    /**
     * @return array<string, mixed> intrarea de manifest a entității
     */
    public function execute(DataExportSource $source, string $tenantId, string $requestId): array
    {
        $disk = Storage::disk(DataExportPaths::DISK);
        $disk->makeDirectory(DataExportPaths::workFolder($tenantId, $requestId));

        $columns = Schema::getColumnListing($source->table());

        $jsonPath = DataExportPaths::part($tenantId, $requestId, "{$source->name}.json");
        $json = $this->open($disk->path($jsonPath));

        $csv = null;

        if ($source->csv) {
            $csvPath = DataExportPaths::part($tenantId, $requestId, "{$source->name}.csv");
            $csv = $this->open($disk->path($csvPath));
            fputcsv($csv, $columns);
        }

        fwrite($json, "[\n");

        $rows = 0;

        $source->newQuery()->chunkById(self::CHUNK, function ($models) use (&$rows, $json, $csv, $columns): void {
            foreach ($models as $model) {
                fwrite($json, ($rows > 0 ? ",\n" : '').$this->encode($model));

                if ($csv !== null) {
                    fputcsv($csv, $this->csvRow($model, $columns));
                }

                $rows++;
            }
        });

        fwrite($json, "\n]\n");
        fclose($json);

        $files = ["{$source->name}.json"];

        if ($csv !== null) {
            fclose($csv);
            $files[] = "{$source->name}.csv";
        }

        $entry = [
            'name' => $source->name,
            'label' => $source->label,
            'rows' => $rows,
            'files' => $files,
            'fields' => $columns,
            'nested' => array_map(
                static fn (string $relation): string => (string) str($relation)->snake(),
                $source->with,
            ),
            'note' => $source->note,
        ];

        $disk->put(
            DataExportPaths::part($tenantId, $requestId, "{$source->name}.meta.json"),
            (string) json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        );

        return $entry;
    }

    /**
     * @return resource
     */
    private function open(string $absolutePath)
    {
        $handle = fopen($absolutePath, 'w');

        if ($handle === false) {
            throw new RuntimeException("Could not open [{$absolutePath}] for writing.");
        }

        return $handle;
    }

    /**
     * `toArray()`, nu `attributesToArray()`: al doilea ar tăia tăcut relațiile imbricate
     * (liniile unei comenzi). Cast-urile modelului se aplică oricum — datele ies ISO-8601,
     * coloanele `jsonb` ies ca obiecte, nu ca șiruri cu ghilimele escapate.
     */
    private function encode(Model $model): string
    {
        return (string) json_encode($model->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param  list<string>  $columns
     * @return list<string|int|float|null>
     */
    private function csvRow(Model $model, array $columns): array
    {
        $attributes = $model->attributesToArray();

        return array_map(function (string $column) use ($attributes) {
            $value = $attributes[$column] ?? null;

            if (is_bool($value)) {
                return $value ? 'true' : 'false';
            }

            if (is_array($value)) {
                return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }

            return $this->sanitize($value);
        }, $columns);
    }

    /**
     * Injecție de formule în CSV (OWASP) — aceeași regulă și aceleași prefixe ca
     * `App\Support\Exports\CsvExporter::sanitizeRow()`, care e `private` și legată de
     * interfața `ExportableList`, deci nereutilizabilă de aici. Duplicat conștient de șase
     * linii, semnalat în raportul lotului ca punct de extras într-un helper comun; a-l
     * OMITE ar fi însemnat ca prima celulă care începe cu `=` din numele unui cont să se
     * execute ca formulă la deschiderea arhivei în Excel.
     */
    private function sanitize(string|int|float|null $value): string|int|float|null
    {
        if (! is_string($value) || $value === '') {
            return $value;
        }

        return in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }
}
