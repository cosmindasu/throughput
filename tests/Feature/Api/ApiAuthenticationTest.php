<?php

namespace Tests\Feature\Api;

use App\Models\ApiToken;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Support\Permissions;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Api\Concerns\IssuesApiTokens;
use Tests\TestCase;

/**
 * Poarta API-ului public — specs.md §18.1/§18.2, `ResolveTenantFromApiToken`.
 *
 * Fiecare caz de aici e un mod de EȘEC: un jeton care nu există, unul revocat, unul
 * expirat, unul al unui membru dezactivat. Cazul fericit e acoperit de restul fișierelor
 * din acest director — aici ne interesează ce se întâmplă când nu e fericit.
 */
class ApiAuthenticationTest extends TestCase
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

    public function test_a_request_without_a_token_is_rejected(): void
    {
        $response = $this->getJson('/api/v1/orders');

        $response->assertStatus(401);
        $this->assertStringContainsString('Authorization: Bearer', $response->json('message'));
    }

    public function test_a_token_that_does_not_exist_is_rejected(): void
    {
        $this->getJson('/api/v1/orders', $this->bearer('1|not-a-real-token'))->assertStatus(401);
        $this->getJson('/api/v1/orders', $this->bearer('garbage'))->assertStatus(401);
    }

    public function test_a_valid_token_resolves_the_tenant_from_the_token_and_not_from_the_url(): void
    {
        $token = $this->issueToken($this->tenant, $this->owner, [ApiToken::ABILITY_ORDERS_READ]);

        $response = $this->getJson('/api/v1/orders', $this->bearer($token));

        $response->assertOk();
        $response->assertJsonStructure(['data', 'meta' => ['page', 'perPage', 'total', 'lastPage']]);

        // §18.2 — nicio cale din API nu conține workspace-ul, deci nu există nici măcar
        // un loc unde un client l-ar putea preciza. Verificat pe rutele înregistrate, nu
        // pe o convenție de nume: o rută nouă adăugată greșit ar trece altfel neobservată.
        foreach (app('router')->getRoutes() as $route) {
            if (str_starts_with((string) $route->getName(), 'api.v1.')) {
                $this->assertStringNotContainsString('{workspace}', $route->uri(), "Route {$route->uri()} carries a workspace segment.");
            }
        }
    }

    public function test_a_revoked_token_stops_working_immediately(): void
    {
        [$apiToken, $plainTextToken] = $this->issueTokenRow($this->tenant, $this->owner, [ApiToken::ABILITY_ORDERS_READ]);

        $this->getJson('/api/v1/orders', $this->bearer($plainTextToken))->assertOk();

        TenantContext::run($this->tenant, fn () => $apiToken->revoke());

        $this->getJson('/api/v1/orders', $this->bearer($plainTextToken))->assertStatus(401);

        // Rândul aplicativ rămâne (istoricul „cine a emis, când a fost revocat"), rândul
        // Sanctum dispare — un jeton revocat nu mai e nici măcar căutabil.
        TenantContext::run($this->tenant, function () use ($apiToken): void {
            $this->assertNotNull(ApiToken::query()->find($apiToken->getKey())?->revoked_at);
        });

        $this->assertSame(0, Sanctum::personalAccessTokenModel()::query()->where('token', $apiToken->token_hash)->count());
    }

    public function test_an_expired_token_is_rejected(): void
    {
        $token = $this->issueToken(
            $this->tenant,
            $this->owner,
            [ApiToken::ABILITY_ORDERS_READ],
            Carbon::now()->addMinutes(5),
        );

        $this->getJson('/api/v1/orders', $this->bearer($token))->assertOk();

        $this->travelTo(Carbon::now()->addHours(2));

        $this->getJson('/api/v1/orders', $this->bearer($token))->assertStatus(401);
    }

    public function test_a_token_issued_by_a_member_who_was_deactivated_stops_working(): void
    {
        $manager = $this->makeMember($this->tenant, 'manager@throughput.dev', Permissions::MANAGER);
        $token = $this->issueToken($this->tenant, $manager, [ApiToken::ABILITY_ORDERS_READ]);

        $this->getJson('/api/v1/orders', $this->bearer($token))->assertOk();

        // BR-TEN-04 — dezactivarea nu șterge membership-ul, îi schimbă starea. Dacă
        // jetonul ar continua să meargă, dezactivarea ar fi doar o blocare de interfață.
        TenantContext::run($this->tenant, fn () => Membership::query()
            ->where('user_id', $manager->getKey())
            ->update(['status' => Membership::STATUS_DEACTIVATED]));

        $this->getJson('/api/v1/orders', $this->bearer($token))->assertStatus(401);
    }

    public function test_last_used_at_is_recorded(): void
    {
        [$apiToken, $plainTextToken] = $this->issueTokenRow($this->tenant, $this->owner, [ApiToken::ABILITY_ORDERS_READ]);

        $this->assertNull($apiToken->last_used_at);

        $this->getJson('/api/v1/orders', $this->bearer($plainTextToken))->assertOk();

        TenantContext::run($this->tenant, function () use ($apiToken): void {
            $this->assertNotNull(ApiToken::query()->find($apiToken->getKey())?->last_used_at);
        });
    }

    public function test_a_sanctum_row_without_a_tenant_marker_is_rejected(): void
    {
        // Un rând scris în afara lui `ApiToken::issue()` (alt pachet, o migrație veche)
        // n-are marcajul `tenant:` — nu există niciun tenant server-side de rezolvat, deci
        // cererea NU trebuie să cadă pe tenantul „curent" sau pe primul găsit.
        $secret = 'plain-secret-value-for-this-test-0001';

        $personalAccessToken = Sanctum::personalAccessTokenModel();
        $row = new $personalAccessToken;
        $row->forceFill([
            'tokenable_type' => $this->owner->getMorphClass(),
            'tokenable_id' => $this->owner->getKey(),
            'name' => 'Rogue token',
            'token' => hash('sha256', $secret),
            'abilities' => [ApiToken::ABILITY_ORDERS_READ],
            'expires_at' => null,
        ])->save();

        $this->getJson('/api/v1/orders', $this->bearer($row->getKey().'|'.$secret))->assertStatus(401);
    }
}
