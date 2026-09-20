<?php

namespace Tests\Feature\Api;

use App\Models\ApiToken;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Permissions;
use Tests\Feature\Api\Concerns\IssuesApiTokens;
use Tests\TestCase;

/**
 * FR-API-05 / specs.md §22.5 — 300 de cereri pe minut per jeton, cu `Retry-After`.
 *
 * Testul coboară plafonul la 3 prin `config()`: ce trebuie dovedit e MECANISMUL și
 * CHEIA (per jeton, nu per IP, nu global), nu cifra — 300 de cereri HTTP reale ar fi
 * adăugat minute la suită ca să verifice aceeași ramură.
 */
class ApiRateLimitTest extends TestCase
{
    use IssuesApiTokens;

    private Tenant $tenant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $this->owner = $this->makeMember($this->tenant, 'owner@throughput.dev', Permissions::OWNER);

    }

    /** Plafonul coboară doar în testele care îl ating — `test_the_configured_limit…` îl citește pe cel real. */
    private function limitTo(int $perMinute): void
    {
        config(['throughput.limits.api_rate_limit_per_minute' => $perMinute]);
    }

    public function test_the_configured_limit_is_the_one_the_specification_asks_for(): void
    {
        // Citit prin `config()`, nu prin `env()`: entrypoint-ul de producție rulează
        // `config:cache`, după care `env()` întoarce implicitul.
        $this->assertSame(300, (int) config('throughput.limits.api_rate_limit_per_minute'));
    }

    public function test_a_token_is_throttled_after_its_limit_and_gets_retry_after(): void
    {
        $this->limitTo(3);
        $token = $this->issueToken($this->tenant, $this->owner, [ApiToken::ABILITY_ORDERS_READ]);

        for ($i = 0; $i < 3; $i++) {
            $this->getJson('/api/v1/orders', $this->bearer($token))->assertOk();
        }

        $response = $this->getJson('/api/v1/orders', $this->bearer($token));

        $response->assertStatus(429);
        $response->assertHeader('X-RateLimit-Limit', '3');
        $this->assertNotNull($response->headers->get('Retry-After'));
        $this->assertStringContainsString('3 requests per minute', $response->json('message'));
    }

    public function test_the_limit_is_per_token_not_per_workspace(): void
    {
        $this->limitTo(3);
        $first = $this->issueToken($this->tenant, $this->owner, [ApiToken::ABILITY_ORDERS_READ]);
        $second = $this->issueToken($this->tenant, $this->owner, [ApiToken::ABILITY_ORDERS_READ]);

        for ($i = 0; $i < 3; $i++) {
            $this->getJson('/api/v1/orders', $this->bearer($first))->assertOk();
        }

        $this->getJson('/api/v1/orders', $this->bearer($first))->assertStatus(429);

        // O integrare zgomotoasă nu trebuie să oprească o alta din același workspace.
        $this->getJson('/api/v1/orders', $this->bearer($second))->assertOk();
    }

    public function test_the_remaining_budget_is_reported_on_successful_responses(): void
    {
        $this->limitTo(3);
        $token = $this->issueToken($this->tenant, $this->owner, [ApiToken::ABILITY_ORDERS_READ]);

        $this->getJson('/api/v1/orders', $this->bearer($token))->assertHeader('X-RateLimit-Remaining', '2');
        $this->getJson('/api/v1/orders', $this->bearer($token))->assertHeader('X-RateLimit-Remaining', '1');
    }

    public function test_unauthenticated_requests_are_throttled_too(): void
    {
        $this->limitTo(3);

        // Altfel o rafală de cereri nesemnate ar trece nelimitat prin limitator și s-ar
        // opri abia la 401, după ce a plătit deja rutarea.
        for ($i = 0; $i < 3; $i++) {
            $this->getJson('/api/v1/orders')->assertStatus(401);
        }

        $this->getJson('/api/v1/orders')->assertStatus(429);
    }
}
