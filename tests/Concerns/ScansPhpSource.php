<?php

namespace Tests\Concerns;

use Illuminate\Database\Eloquent\Model;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;

/**
 * Helper-e de scanare statică a sursei PHP, extrase din `tests\Unit\ArchitectureTest`
 * (TEST-07, audit 2026-09-23): `ContactOptOutFilterGuardTest` reimplementa identic
 * `codeWithoutComments()`/`phpFilesIn()`/`relative()`, caracter cu caracter — o gardă
 * structurală nouă care copiază codul gărzii de care se inspiră, în loc să-l refolosească.
 *
 * **Atenție la `__DIR__` în `relative()`**: constanta se rezolvă la fișierul unde e SCRIS
 * codul, nu la clasa care îl folosește — deci `__DIR__` de mai jos e mereu
 * `tests/Concerns`, indiferent care test apelează metoda. `tests/Concerns` e la aceeași
 * adâncime față de rădăcina proiectului ca `tests/Unit` (ambele un singur nivel sub
 * `tests/`), deci `__DIR__.'/../../'` tot ajunge la rădăcină — dacă traitul ăsta s-ar muta
 * vreodată mai adânc (ex. `tests/Concerns/Architecture/`), constanta ar trebui recalculată,
 * nu presupusă neschimbată.
 *
 * Nu extinde nimic și nu ține stare — proiectat să fie amestecat (`use`) atât în teste care
 * NU ating baza de date (`ArchitectureTest`, `ContactOptOutFilterGuardTest`, ambele extind
 * direct `PHPUnit\Framework\TestCase`), cât și în teste care o ating (`Tests\TestCase`),
 * fără nicio presupunere despre părinte.
 */
trait ScansPhpSource
{
    /**
     * Codul fără comentarii.
     *
     * Prima variantă a acestor verificări citea fișierul brut și pica pe COMENTARIILE care
     * explicau tocmai regula („citit din config, niciodată din env()") — un test care
     * pedepsește documentarea regulii pe care o impune nu rezistă nici o săptămână.
     */
    private function codeWithoutComments(SplFileInfo $file): string
    {
        $code = '';

        foreach (token_get_all(file_get_contents($file->getPathname())) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $code .= is_array($token) ? $token[1] : $token;
        }

        return $code;
    }

    /**
     * @return list<SplFileInfo>
     */
    private function phpFilesIn(string $directory): array
    {
        $files = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory)) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file;
            }
        }

        usort($files, fn (SplFileInfo $a, SplFileInfo $b) => strcmp($a->getPathname(), $b->getPathname()));

        return $files;
    }

    private function relative(SplFileInfo $file): string
    {
        $root = realpath(__DIR__.'/../../').'/';

        return str_replace($root, '', $file->getRealPath());
    }

    /**
     * Toate clasele Eloquent direct în `app/Models/` (neredundant, nerecursiv — `Scopes/` nu
     * conține modele). `glob()`, nu `phpFilesIn()` de mai sus: acela e recursiv prin design,
     * pentru verificările pe tot `app/`, și ar coborî și în `Scopes/`.
     *
     * @return list<class-string<Model>>
     */
    private function modelClasses(): array
    {
        $classes = [];

        foreach (glob(__DIR__.'/../../app/Models/*.php') ?: [] as $file) {
            $class = 'App\\Models\\'.basename($file, '.php');

            if (! class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);

            if ($reflection->isAbstract() || ! $reflection->isSubclassOf(Model::class)) {
                continue;
            }

            $classes[] = $class;
        }

        sort($classes);

        return $classes;
    }
}
