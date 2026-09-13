<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Căile de pagini Inertia trebuie să coincidă literă cu literă cu directorul de pe disc.
 *
 * `assertInertia` caută componenta în `inertia.pages.paths` (`testing.ensure_pages_exist`).
 * Implicitul din inertia-laravel 3 e `js/pages`, iar paginile proiectului stau în `js/Pages`.
 * Pe macOS diferența nu se vede, fiindcă sistemul de fișiere e insensibil la majuscule, deci
 * suita trecea local. Pe runner-ul Linux au picat 40 de teste, la primul run CI de după Faza 2.
 *
 * `is_dir()` ar trece și el pe macOS, deci testul compară numele cu listarea reală a
 * directorului părinte: `scandir()` întoarce numele exact, cu majusculele de pe disc.
 */
class InertiaPagePathsTest extends TestCase
{
    public function test_every_configured_page_path_matches_the_directory_name_on_disk(): void
    {
        foreach (config('inertia.pages.paths') as $path) {
            $this->assertContains(
                basename($path),
                scandir(dirname($path)),
                "Calea de pagini Inertia [{$path}] nu există pe disc cu exact aceste majuscule; pe Linux, assertInertia n-ar găsi nicio pagină."
            );
        }
    }
}
