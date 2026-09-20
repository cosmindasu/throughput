<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * FR-API-04 — contractul publicat: `GET /api/documentation` (Swagger UI) și
 * `GET /api/openapi.yaml` (documentul însuși).
 *
 * PUBLICE, fără jeton, deliberat: §18 vinde ideea „cineva din afară poate să-l citească
 * și să-l integreze fără să întrebe", iar un contract care cere deja credențiale ca să fie
 * citit nu face asta. Nu expune niciun rând de date — doar forma.
 *
 * SWAGGER UI E SELF-HOSTED, prin Vite (`resources/js/swagger.ts`), nu de pe un CDN.
 * Prima versiune îl lua de pe jsDelivr, cu versiune fixată — ceea ce funcționa exact
 * pentru că ruta trăiește pe grupul `api`, unde `SecurityHeaders` nu era aplicat: era
 * SINGURA pagină HTML din aplicație fără CSP, într-un proiect al cărui `script-src` e
 * `'self'` fără nicio excepție. Riscul nu era furtul cookie-ului de sesiune (`http_only`
 * îl apără de citire), ci faptul că browserul îl TRIMITE: un script compromis pe originea
 * noastră face `fetch` same-site, citește tokenul CSRF dintr-o pagină a aplicației și
 * acționează ca vizitatorul logat — iar demo-ul e public, cu scriere reală, deci
 * vizitatorul chiar e logat. `SecurityHeaders` se aplică acum explicit pe această rută
 * (`routes/api.php`), iar scriptul vine de pe originea proprie.
 *
 * Documentul YAML e citit de pe disc la fiecare cerere, nu cache-uit: e sursa de adevăr
 * a contractului (§18.6), iar un cache ar putea servi o versiune veche exact în minutul
 * de după un deploy. Fișierul are câțiva zeci de KB.
 */
final class DocumentationController extends Controller
{
    private const SPEC_PATH = 'openapi/throughput-v1.yaml';

    public function index(): Response
    {
        return response()
            ->view('api.documentation')
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }

    public function spec(): Response
    {
        $path = base_path(self::SPEC_PATH);

        abort_unless(is_file($path), SymfonyResponse::HTTP_NOT_FOUND);

        return response((string) file_get_contents($path))
            ->header('Content-Type', 'application/yaml; charset=UTF-8')
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }
}
