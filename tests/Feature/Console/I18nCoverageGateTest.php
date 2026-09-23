<?php

namespace Tests\Feature\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * TEST-11 — completează `tests/Unit/Console/I18nCoverageTest.php` (logica de detecție, pe
 * cataloage SINTETICE, prin Reflection pe metodele private). Comanda `i18n:coverage`
 * hardcodează `base_path('lang')`/`resource_path('js/locales')` direct în `handle()`
 * (vezi docblock-ul acelui fișier pentru schimbarea minimă care ar permite injectarea unei
 * rădăcini alternative) — fără un punct de injectare, singurul mod de a exercita
 * `handle()` ÎNSUȘI (nu doar metodele lui private) este pe cataloagele REALE ale
 * repo-ului, exact cum rulează în CI.
 *
 * Nu e un test „sintetic dezechilibrat" — e intenționat cuplat la starea reală a
 * `lang/`/`resources/js/locales/`, ca gardă de fum pentru cablajul complet (rădăcini
 * hardcodate → citire de disc → cod de ieșire → mesaj), pe care testele Unit, prin
 * design, nu-l pot atinge.
 */
class I18nCoverageGateTest extends TestCase
{
    public function test_the_gate_passes_on_the_repositorys_current_catalogs(): void
    {
        $exitCode = Artisan::call('i18n:coverage');

        // O SINGURĂ citire — `Artisan::output()` întoarce `BufferedOutput::fetch()`, care
        // GOLEȘTE bufferul la citire (comportamentul Symfony, nu un artefact al testului):
        // o a doua citire ar întoarce mereu un șir gol, indiferent ce a scris comanda.
        $output = Artisan::output();

        $this->assertSame(Command::SUCCESS, $exitCode, "i18n:coverage a eșuat pe cataloagele reale ale repo-ului:\n{$output}");
        $this->assertStringContainsString('Acoperire chei i18n OK', $output);
    }
}
