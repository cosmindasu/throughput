<?php

namespace Tests\Feature\Ops;

use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * OPS — `nginx` trebuie să-i spună lui Traefik PE CARE rețea să-l contacteze.
 *
 * ## Ce a costat lipsa ei
 *
 * Containerul `nginx` ajunge pe DOUĂ rețele: `internal` (declarată în compose, ca să vadă
 * `app:9000`) și cea pe care Coolify o atașează pentru rutare. Traefik e doar pe a doua.
 * Măsurat de pe `coolify-proxy`, 2026-09-29: `172.27.0.7` (rețeaua Coolify) răspunde,
 * `172.28.0.5` (`internal`) expiră.
 *
 * Fără eticheta asta, Traefik alege singur la fiecare rezolvare nouă. Când nimerea a doua
 * adresă, cererea cădea în gol exact 30,03 s — `dialTimeout`-ul lui implicit — și
 * vizitatorul primea **504**. Rata măsurată: 3 din 13 cereri trimise după o perioadă de
 * inactivitate, niciuna ajungând vreodată în logul de acces al lui nginx.
 *
 * ## De ce e nevoie de un test, și nu de un comentariu
 *
 * Defectul ăsta e invizibil pentru absolut tot restul: aplicația e sănătoasă, containerele
 * `healthy`, baza cu o conexiune, 20 de cereri în rafală toate 200 (Traefik refolosește o
 * conexiune deja deschisă — inactivitatea nu provoacă defectul, doar forțează o rezolvare
 * nouă). Nu apare în niciun log de eroare, nici al aplicației, nici al proxy-ului. A fost
 * găsit abia după ce a fost urmărit prin patru ipoteze greșite.
 *
 * Cine șterge linia din compose nu va vedea nimic stricat local, în CI, sau la deploy.
 * Va vedea, peste zile, 504-uri intermitente fără explicație. De-aia garda e aici.
 */
class ProxyNetworkLabelTest extends TestCase
{
    private const COOLIFY_NETWORK = 'yy5kcf4ijq4eo754ejo9tbtn';

    /**
     * @return array<string, mixed>
     */
    private function nginxService(): array
    {
        $path = dirname(__DIR__, 3).'/docker-compose.coolify.yml';

        $this->assertFileExists($path, 'Descriptorul de deployment Coolify a dispărut.');

        $compose = Yaml::parseFile($path);

        $this->assertArrayHasKey('nginx', $compose['services'] ?? [], 'Serviciul `nginx` nu mai există în compose.');

        return $compose['services']['nginx'];
    }

    public function test_nginx_tells_traefik_which_network_to_dial(): void
    {
        $labels = $this->nginxService()['labels'] ?? [];

        $this->assertNotEmpty(
            $labels,
            'Serviciul `nginx` nu mai are nicio etichetă. `traefik.docker.network` era una dintre '
            .'ele, iar fără ea Traefik alege singur între două adrese, dintre care una nu răspunde.'
        );

        $expected = 'traefik.docker.network='.self::COOLIFY_NETWORK;

        $this->assertContains(
            $expected,
            $labels,
            "Lipsește `{$expected}`. Vezi docblock-ul: fără ea, o parte din vizitatori așteaptă "
            .'30 de secunde și primesc 504, fără nicio urmă în vreun log.'
        );
    }

    /**
     * Eticheta are sens doar cât timp `nginx` chiar e pe o a DOUA rețea. Dacă `internal` ar
     * dispărea de pe el, containerul ar avea o singură adresă, ambiguitatea s-ar stinge
     * singură — iar eticheta ar deveni o valoare fixată degeaba, care s-ar putea desincroniza
     * tăcut de numele real al rețelei. Testul leagă cele două fapte, ca oricare dintre ele să
     * nu se poată schimba singur.
     */
    public function test_the_label_is_still_necessary(): void
    {
        $networks = $this->nginxService()['networks'] ?? [];

        $this->assertContains(
            'internal',
            $networks,
            '`nginx` nu mai e pe `internal`, deci probabil are o singură adresă. Dacă e așa, '
            .'`traefik.docker.network` nu mai rezolvă nimic și ar trebui scoasă odată cu testul '
            .'ăsta — nu lăsată să indice o rețea care s-ar putea redenumi.'
        );
    }
}
