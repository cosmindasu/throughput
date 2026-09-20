<?php

namespace Tests\Feature\Api;

use App\Models\ApiToken;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * FR-API-04 — `openapi/throughput-v1.yaml` e SURSA DE ADEVĂR a contractului, nu o
 * documentație scrisă alături.
 *
 * Spectral (CI) validează FORMA documentului. Ce nu poate verifica e dacă documentul
 * descrie aplicația care chiar rulează — de asta se ocupă testul de mai jos: fiecare rută
 * `api.v1.*` înregistrată trebuie să aibă o operație în document, și invers. Fără el,
 * „specificația e validă" și „specificația e adevărată" sunt două lucruri diferite, iar
 * numai al doilea contează pentru cineva care integrează.
 */
class OpenApiContractTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $spec;

    protected function setUp(): void
    {
        parent::setUp();

        $this->spec = Yaml::parseFile(base_path('openapi/throughput-v1.yaml'));
    }

    public function test_the_document_is_openapi_31_and_versioned_on_the_path(): void
    {
        $this->assertSame('3.1.0', $this->spec['openapi']);

        // ADR-008 — versionarea e pe cale. Serverul o poartă, deci căile din document
        // sunt relative la `/api/v1` și nu repetă versiunea.
        foreach ($this->spec['servers'] as $server) {
            $this->assertStringEndsWith('/api/v1', $server['url']);
        }

        foreach (array_keys($this->spec['paths']) as $path) {
            $this->assertStringNotContainsString('/v1/', $path);
            // §18.2 — niciun segment de workspace, nicăieri.
            $this->assertStringNotContainsString('{workspace}', $path);
        }
    }

    public function test_every_registered_v1_route_is_documented(): void
    {
        $missing = [];

        foreach (Route::getRoutes() as $route) {
            $name = (string) $route->getName();

            if (! str_starts_with($name, 'api.v1.')) {
                continue;
            }

            $path = '/'.ltrim(str_replace('api/v1', '', $route->uri()), '/');

            foreach ($route->methods() as $method) {
                if (in_array($method, ['HEAD', 'OPTIONS'], true)) {
                    continue;
                }

                $verb = strtolower($method);

                if (! isset($this->spec['paths'][$path][$verb])) {
                    $missing[] = strtoupper($verb).' '.$path;
                }
            }
        }

        $this->assertSame([], $missing, 'Routes exist but are absent from openapi/throughput-v1.yaml: '.implode(', ', $missing));
    }

    public function test_every_documented_operation_exists_as_a_route(): void
    {
        $routes = [];

        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with((string) $route->getName(), 'api.v1.')) {
                continue;
            }

            foreach ($route->methods() as $method) {
                $routes[] = strtolower($method).' /'.ltrim(str_replace('api/v1', '', $route->uri()), '/');
            }
        }

        $phantom = [];

        foreach ($this->spec['paths'] as $path => $operations) {
            foreach (array_keys($operations) as $verb) {
                if (! in_array($verb.' '.$path, $routes, true)) {
                    $phantom[] = strtoupper($verb).' '.$path;
                }
            }
        }

        $this->assertSame([], $phantom, 'Documented but not routed: '.implode(', ', $phantom));
    }

    public function test_the_three_endpoints_that_require_idempotency_declare_the_header(): void
    {
        // §18.4 numește exact aceste trei. Dacă lista se schimbă, se schimbă în amândouă
        // locurile — documentul și `routes/api.php` — sau testul cade.
        foreach (['/orders', '/invoices', '/stock-movements'] as $path) {
            $parameters = $this->spec['paths'][$path]['post']['parameters'] ?? [];

            $this->assertContains(
                ['$ref' => '#/components/parameters/IdempotencyKey'],
                $parameters,
                "POST {$path} must document the Idempotency-Key header.",
            );
        }

        $required = $this->spec['components']['parameters']['IdempotencyKey'];
        $this->assertTrue($required['required']);
        $this->assertSame('header', $required['in']);
    }

    public function test_the_security_scheme_is_the_one_the_api_actually_uses(): void
    {
        $this->assertSame([['sanctumToken' => []]], $this->spec['security']);
        $this->assertSame('http', $this->spec['components']['securitySchemes']['sanctumToken']['type']);
        $this->assertSame('bearer', $this->spec['components']['securitySchemes']['sanctumToken']['scheme']);
    }

    public function test_every_scope_in_the_catalog_is_mentioned_in_the_document(): void
    {
        $document = (string) file_get_contents(base_path('openapi/throughput-v1.yaml'));

        foreach (ApiToken::allowedAbilities() as $ability) {
            $this->assertStringContainsString($ability, $document, "Scope {$ability} is not documented.");
        }
    }

    public function test_the_documentation_page_is_public_and_not_indexed(): void
    {
        $response = $this->get('/api/documentation');

        $response->assertOk();
        // §4.4/FR-PUB-04 — `NoIndexHeaders` e pe grupul `web`; rutele astea sunt pe `api`,
        // deci antetul se pune explicit în controller. Fără el ar fi lipsit tăcut.
        $response->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->assertStringContainsString('swagger-ui', $response->getContent());
        $this->assertStringContainsString('/api/openapi.yaml', $response->getContent());
    }

    /**
     * Decizia proprietarului (2026-09-20): Swagger UI e SELF-HOSTED, nu de pe un CDN.
     *
     * Prima versiune îl încărca de pe jsDelivr, ceea ce funcționa exact pentru că ruta
     * trăiește pe grupul `api`, unde `SecurityHeaders` nu era aplicat — deci era singura
     * pagină HTML a aplicației fără CSP, într-un proiect al cărui `script-src` e `'self'`
     * fără nicio excepție. Regresia pe care o păzește testul ăsta e tăcută în ambele
     * direcții: un script străin readăugat n-ar da nicio eroare vizibilă (pagina ar merge
     * perfect), iar scoaterea middleware-ului de pe rută la fel.
     */
    public function test_the_documentation_page_carries_csp_and_loads_no_third_party_script(): void
    {
        $response = $this->get('/api/documentation');

        $response->assertOk();

        $csp = (string) $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("script-src 'self'", $csp, 'Pagina de contract a rămas fără CSP — vezi `routes/api.php`.');

        $html = (string) $response->getContent();
        $this->assertStringNotContainsString('//cdn.', $html);
        $this->assertStringNotContainsString('jsdelivr', $html);
        $this->assertStringNotContainsString('unpkg', $html);

        // Un `<script>` inline ar fi blocat de politica de mai sus, deci URL-ul
        // documentului ajunge în JS printr-un atribut `data-`.
        $this->assertStringContainsString('data-spec-url', $html);
    }

    public function test_the_specification_itself_is_served(): void
    {
        $response = $this->get('/api/openapi.yaml');

        $response->assertOk();
        $response->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->assertStringContainsString('application/yaml', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('openapi: 3.1.0', $response->getContent());
    }
}
