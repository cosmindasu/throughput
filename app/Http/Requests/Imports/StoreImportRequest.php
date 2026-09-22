<?php

namespace App\Http\Requests\Imports;

use App\Models\Import;
use App\Support\Imports\ImportableResources;
use App\Support\Imports\ImportFileRowCounter;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Pasul 1 — Upload (§14.1, FR-IMP-02). Extensie csv/xlsx, dimensiune ≤
 * `import_max_file_mb`, număr de rânduri ≤ `import_max_rows` — TOATE verificate aici, ca
 * mesajul de eroare să fie clar și legat de câmp, nu o excepție brută mai târziu în job.
 *
 * `mimes:csv,txt,xlsx` — nu o lejeritate: fișierele CSV exportate de Excel/Sheets pe unele
 * platforme ajung cu `Content-Type: text/plain`, pe care validarea `mimes` a Laravel îl
 * potrivește pe extensia `txt`, nu `csv`. Fără `txt` în listă, un CSV perfect valid ar fi
 * respins pe baza mime type-ului, nu a conținutului — motivul e detectarea, nu o schimbare de
 * cerință (spec cere „csv/xlsx"); citirea ulterioară (`ImportFilePath::extension()`) tratează
 * orice non-`xlsx` ca CSV.
 */
final class StoreImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Import::class);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $maxKilobytes = max(1, (int) config('throughput.limits.import_max_file_mb')) * 1024;

        return [
            'resource_type' => ['required', Rule::in(ImportableResources::types())],
            'file' => ['required', 'file', 'mimes:csv,txt,xlsx', "max:{$maxKilobytes}"],
        ];
    }

    /**
     * FR-IMP-02 — plafonul de RÂNDURI, distinct de dimensiune (un fișier de 20 MB cu rânduri
     * scurte poate depăși 50.000 de rânduri și invers). Verificat DUPĂ ce `mimes`/`max` au
     * trecut deja (altfel am număra rânduri într-un fișier care oricum va fi respins).
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->has('file') || ! $this->hasFile('file')) {
                return;
            }

            $maxRows = max(1, (int) config('throughput.limits.import_max_rows'));
            $file = $this->file('file');
            $extension = strtolower((string) $file->getClientOriginalExtension()) === 'xlsx' ? 'xlsx' : 'csv';
            $rows = ImportFileRowCounter::count((string) $file->getRealPath(), $extension);

            if ($rows > $maxRows) {
                $validator->errors()->add(
                    'file',
                    __('rules.imports.row_limit_exceeded', ['rows' => $rows, 'max_rows' => $maxRows]),
                );

                return;
            }

            // P2 (review general) — un fișier cu ZERO rânduri de date (doar antet, sau
            // complet gol) trecea validarea și se „finaliza" instant ca `completed`, 0/0, fără
            // niciun mesaj: mai degrabă o greșeală a utilizatorului (fișier greșit ales) decât
            // un import valid.
            if ($rows === 0) {
                $validator->errors()->add('file', __('rules.imports.no_data_rows'));
            }
        });
    }
}
