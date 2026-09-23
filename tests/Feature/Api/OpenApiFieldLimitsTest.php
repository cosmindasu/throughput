<?php

namespace Tests\Feature\Api;

use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * HTTP-01 (audit 2026-09-23) — `openapi/throughput-v1.yaml` ne documenta `minimum: 0` pe
 * `deals.value` / `orders.lines[].unit_price` / `orders.lines[].discount`, dar tăcea despre
 * plafonul `max:` impus de `StoreDealRequest`/`StoreOrderRequest` — un 422 real (valoare peste
 * plafon) nu era descris de contract.
 *
 * Testul de mai jos NU citește regulile din FormRequest direct: `rules()` cere un tenant
 * curent (`TenantScope::requireCurrentTenantId()`), deci ar avea nevoie de context HTTP complet
 * doar ca să extragă o constantă. În loc de asta, fixează valorile — copiate EXACT din sursă,
 * cu calea la fiecare — și verifică documentul față de ele. Dacă vreuna dintre cele două părți
 * se schimbă fără cealaltă, testul cade.
 */
class OpenApiFieldLimitsTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $spec;

    protected function setUp(): void
    {
        parent::setUp();

        $this->spec = Yaml::parseFile(base_path('openapi/throughput-v1.yaml'));
    }

    /**
     * `App\Http\Requests\Deals\StoreDealRequest::rules()`:
     * `'value' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99']`
     * (aceeași pereche și în `UpdateDealRequest`, care nu e parte a contractului API —
     * §18 nu are `PATCH /deals/{deal}`).
     */
    public function test_create_deal_request_documents_the_value_ceiling(): void
    {
        $value = $this->spec['components']['schemas']['CreateDealRequest']['properties']['value'];

        $this->assertSame(0, $value['minimum']);
        $this->assertSame(9999999999.99, $value['maximum']);
    }

    /**
     * `App\Http\Requests\Orders\StoreOrderRequest::rules()`:
     * `'lines.*.unit_price' => ['nullable', 'numeric', 'min:0', 'max:9999999.99']`
     * `'lines.*.discount' => ['nullable', 'numeric', 'min:0', 'max:9999999.99', ...]`
     * (aceeași pereche în `UpdateOrderRequest`, care nu e parte a contractului API).
     */
    public function test_create_order_request_documents_the_line_price_and_discount_ceilings(): void
    {
        $line = $this->spec['components']['schemas']['CreateOrderRequest']['properties']['lines']['items']['properties'];

        $this->assertSame(0, $line['unit_price']['minimum']);
        $this->assertSame(9999999.99, $line['unit_price']['maximum']);

        $this->assertSame(0, $line['discount']['minimum']);
        $this->assertSame(9999999.99, $line['discount']['maximum']);
    }
}
