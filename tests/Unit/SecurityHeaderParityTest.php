<?php

namespace Tests\Unit;

use App\Http\Middleware\SecurityHeaders;
use PHPUnit\Framework\TestCase;

/**
 * `SecurityHeaders` și `docker/nginx/default.conf` emit ACELEAȘI patru antete, deliberat:
 * PHP le pune pe răspunsurile aplicației, nginx pe fișierele statice pe care PHP nu le vede
 * niciodată (docblock-ul din `SecurityHeaders`). Pe răspunsurile PHP ajung deci de două ori,
 * fiindcă `location ~ \.php$` moștenește `add_header`-ele de la nivel de `server`.
 *
 * Duplicarea e inofensivă **cât timp valorile sunt identice**. Devine un defect real în clipa
 * în care diverg: pentru `X-Frame-Options`, două valori CONTRADICTORII fac browserele să
 * ignore antetul cu totul — adică protecția dispare exact pentru că a fost declarată de două
 * ori. `SecurityHeadersTest` verifică doar latura PHP; nimic nu lega până acum fișierul nginx
 * de constantele din cod, deci o editare într-un singur loc ar fi trecut tăcută.
 *
 * Capcana nu e ipotetică: în auditul altui proiect din același lot, HSTS exista într-unul
 * dintre cele două Caddyfile-uri și lipsea din celălalt, iar documentația îl bifa ca făcut.
 *
 * Testul citește fișiere, nu bootează aplicația — de aceea extinde `PHPUnit\Framework\TestCase`
 * direct, ca `ArchitectureTest`.
 */
class SecurityHeaderParityTest extends TestCase
{
    /**
     * Antetele pe care nginx le repetă, cu sursa de adevăr din PHP.
     *
     * @return array<string, string>
     */
    private function expectedHeaders(): array
    {
        return [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => SecurityHeaders::PERMISSIONS_POLICY,
        ];
    }

    private function nginxConfig(): string
    {
        $path = dirname(__DIR__, 2).'/docker/nginx/default.conf';

        $contents = file_get_contents($path);

        $this->assertIsString($contents, "Nu am putut citi {$path}.");

        return $contents;
    }

    public function test_nginx_repeats_the_same_values_as_the_php_middleware(): void
    {
        $config = $this->nginxConfig();

        foreach ($this->expectedHeaders() as $header => $expected) {
            preg_match_all(
                '/add_header\s+'.preg_quote($header, '/').'\s+"([^"]*)"/',
                $config,
                $matches
            );

            $found = $matches[1];

            $this->assertNotEmpty(
                $found,
                "`{$header}` nu apare deloc în docker/nginx/default.conf. Fișierele statice "
                .'servite direct de nginx ar rămâne fără el.'
            );

            foreach ($found as $value) {
                $this->assertSame(
                    $expected,
                    $value,
                    "`{$header}` diferă între nginx și SecurityHeaders. Valori diferite pentru "
                    .'același antet pe același răspuns sunt mai rele decât una singură — vezi '
                    .'docblock-ul testului.'
                );
            }
        }
    }

    /**
     * `add_header` într-un `location` ANULEAZĂ moștenirea celor de la nivelul de deasupra
     * (capcană notată deja în `default.conf`). `location ^~ /build/` are nevoie de propriul
     * `Cache-Control`, deci trebuie să le repete pe toate patru — altfel exact fișierele
     * cu cea mai lungă viață de cache rămân fără antete de securitate.
     */
    public function test_the_build_location_repeats_every_header_it_cancels(): void
    {
        $config = $this->nginxConfig();

        $start = strpos($config, 'location ^~ /build/');

        $this->assertNotFalse($start, 'Blocul `location ^~ /build/` a dispărut din configurație.');

        $block = substr($config, $start);
        $end = strpos($block, "\n    }");
        $block = $end === false ? $block : substr($block, 0, $end);

        foreach (array_keys($this->expectedHeaders()) as $header) {
            $this->assertStringContainsString(
                "add_header {$header} ",
                $block,
                '`location ^~ /build/` are `add_header`-uri proprii, deci NU mai moștenește '
                ."nimic de la nivelul `server`. `{$header}` lipsește din el."
            );
        }
    }
}
