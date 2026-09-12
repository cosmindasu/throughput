<?php

namespace Database\Seeders\Demo;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Fixture STATIC de import (plan §7.8, specs.md §14.3/US-IMP-01): 10.000 de rânduri de
 * produse/variante, cu ~200 erori plantate (SKU duplicat, preț non-numeric, câmp
 * obligatoriu lipsă) — folosit de Faza 4. Generat O SINGURĂ DATĂ, idempotent: dacă
 * fișierul există deja, NU se regenerează la fiecare `demo:seed-volume`/`demo:reset`, ca
 * Faza 4 să nu depindă de un random seed variabil (task brief, pct. g).
 */
final class ImportFixtureSeeder
{
    private const TOTAL_ROWS = 10000;

    public function run(?Command $command = null): void
    {
        $path = database_path('seeders/fixtures/import-products-with-errors.csv');

        if (File::exists($path)) {
            $command?->components->info('Import fixture already exists — skipped (static by design).');

            return;
        }

        File::ensureDirectoryExists(dirname($path));

        // Determinist indiferent de seed-ul rulării curente — fixture-ul e static prin
        // conținut, nu doar prin faptul că nu se rescrie.
        mt_srand(20260912);

        $categories = ['Bolts', 'Nuts', 'Washers', 'Screws', 'Hose', 'Fittings', 'Valves', 'Tables', 'Cookware', 'Storage'];
        $units = ['each', 'box', 'pallet'];

        $rows = [];

        for ($i = 1; $i <= self::TOTAL_ROWS; $i++) {
            $rows[] = [
                'sku' => 'IMP-'.str_pad((string) $i, 6, '0', STR_PAD_LEFT),
                'name' => 'Sample Product '.$i,
                'category' => $categories[$i % count($categories)],
                'unit' => $units[$i % count($units)],
                'price' => number_format(mt_rand(50, 50000) / 100, 2, '.', ''),
                'cost' => number_format(mt_rand(20, 30000) / 100, 2, '.', ''),
                'weight' => number_format(mt_rand(10, 5000) / 1000, 3, '.', ''),
            ];
        }

        // ~70 SKU-uri duplicate (a doua apariție a unui SKU deja folosit mai devreme în fișier).
        for ($n = 0; $n < 70; $n++) {
            $target = 199 + $n * 40;
            $source = $target - 77;
            $rows[$target]['sku'] = $rows[$source]['sku'];
        }

        // ~70 prețuri non-numerice.
        for ($n = 0; $n < 70; $n++) {
            $target = 2999 + $n * 40;
            $rows[$target]['price'] = 'N/A';
        }

        // ~60 câmpuri obligatorii lipsă (nume gol).
        for ($n = 0; $n < 60; $n++) {
            $target = 5999 + $n * 40;
            $rows[$target]['name'] = '';
        }

        $handle = fopen($path, 'w');
        fputcsv($handle, ['SKU', 'Product Name', 'Category', 'Unit of Measure', 'Price', 'Cost', 'Weight']);

        foreach ($rows as $row) {
            fputcsv($handle, [$row['sku'], $row['name'], $row['category'], $row['unit'], $row['price'], $row['cost'], $row['weight']]);
        }

        fclose($handle);

        $command?->components->info('Generated import fixture: 200 planted errors on '.self::TOTAL_ROWS.' rows.');
    }
}
