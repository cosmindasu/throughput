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
 * `X-Robots-Tag` se pune AICI, explicit: `NoIndexHeaders` e adăugat pe grupul `web`
 * (`bootstrap/app.php`), iar rutele astea sunt pe grupul `api`. FR-PUB-04 cere `noindex`
 * pe toate paginile, nu doar pe cele care se întâmplă să treacă prin grupul potrivit.
 *
 * Documentul YAML e citit de pe disc la fiecare cerere, nu cache-uit: e sursa de adevăr
 * a contractului (§18.6), iar un cache ar putea servi o versiune veche exact în minutul
 * de după un deploy. Fișierul are câțiva zeci de KB.
 */
final class DocumentationController extends Controller
{
    private const SPEC_PATH = 'openapi/throughput-v1.yaml';

    /** Swagger UI, versiune FIXATĂ — o etichetă mobilă ar schimba tăcut pagina publicată. */
    private const SWAGGER_UI_VERSION = '5.17.14';

    public function index(): Response
    {
        return response($this->html())
            ->header('Content-Type', 'text/html; charset=UTF-8')
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

    private function html(): string
    {
        $base = 'https://cdn.jsdelivr.net/npm/swagger-ui-dist@'.self::SWAGGER_UI_VERSION;
        $specUrl = e(route('api.documentation.spec'));

        return <<<HTML
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <meta name="robots" content="noindex, nofollow">
            <title>Throughput API — v1</title>
            <link rel="stylesheet" href="{$base}/swagger-ui.css">
        </head>
        <body>
            <div id="swagger-ui"></div>
            <script src="{$base}/swagger-ui-bundle.js" crossorigin></script>
            <script>
                window.ui = SwaggerUIBundle({
                    url: '{$specUrl}',
                    dom_id: '#swagger-ui',
                    deepLinking: true,
                    // Demo public cu scriere reală (§22): „Try it out" ar trimite cereri
                    // autentificate cu jetonul pe care cititorul îl lipește în bară. Rămâne
                    // activ — e exact demonstrația — dar fără niciun jeton preîncărcat.
                    tryItOutEnabled: true
                });
            </script>
        </body>
        </html>
        HTML;
    }
}
