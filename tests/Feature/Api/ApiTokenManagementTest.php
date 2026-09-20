<?php

namespace Tests\Feature\Api;

use App\Models\ApiToken;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Support\Permissions;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Api\Concerns\IssuesApiTokens;
use Tests\TestCase;

/**
 * Settings → API tokens — FR-API-02 („doar Owner și Manager pot crea/revoca"), US-API-01
 * („primesc valoarea token-ului o singură dată").
 *
 * Ecranul e singura parte WEB a acestui lot, deci singura care trece prin
 * `auth → session.context → workspace`.
 */
class ApiTokenManagementTest extends TestCase
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

    private function url(string $suffix = ''): string
    {
        return '/'.$this->tenant->slug.'/settings/api-tokens'.$suffix;
    }

    public function test_an_owner_sees_the_screen_with_the_scope_catalogue(): void
    {
        $response = $this->actingAs($this->owner)->get($this->url());

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Settings/ApiTokens/Index')
            ->has('tokens')
            ->has('abilities', count(ApiToken::allowedAbilities()))
            ->where('can.create', true)
            ->where('can.revoke', true)
            ->where('plainTextToken', null)
        );
    }

    public function test_a_manager_may_manage_tokens_too(): void
    {
        $manager = $this->makeMember($this->tenant, 'manager@throughput.dev', Permissions::MANAGER);

        $this->actingAs($manager)->get($this->url())
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('can.create', true));
    }

    public function test_an_agent_and_a_viewer_are_refused(): void
    {
        foreach ([Permissions::AGENT, Permissions::VIEWER] as $role) {
            $user = $this->makeMember($this->tenant, strtolower($role).'@throughput.dev', $role);

            $this->actingAs($user)->get($this->url())->assertForbidden();

            $this->actingAs($user)->post($this->url(), [
                'name' => 'Sneaky',
                'abilities' => [ApiToken::ABILITY_ORDERS_READ],
            ])->assertForbidden();
        }
    }

    public function test_creating_a_token_shows_the_plain_value_exactly_once(): void
    {
        // `actingAs()` golește sesiunea (`Tests\TestCase`, din cauza lui
        // `AuthenticateSession`) — chemat a doua oară, ar arunca exact flash-ul pe care
        // îl verificăm. Autentificare O SINGURĂ dată, apoi cererile una după alta, ca
        // într-un browser.
        $this->actingAs($this->owner);

        $this->post($this->url(), [
            'name' => 'ERP sync',
            'abilities' => [ApiToken::ABILITY_ORDERS_READ],
        ])->assertRedirect($this->url());

        $first = $this->get($this->url());
        $plainTextToken = $first->viewData('page')['props']['plainTextToken'] ?? null;

        $this->assertIsString($plainTextToken);
        $this->assertStringContainsString('|', $plainTextToken, 'The token keeps the Sanctum {id}|{secret} shape.');

        // A doua încărcare a aceleiași pagini nu-l mai are: flash-ul a fost consumat.
        $this->get($this->url())
            ->assertInertia(fn (Assert $page) => $page->where('plainTextToken', null));

        // Și nu există nicăieri în bază — doar hash-ul.
        TenantContext::run($this->tenant, function () use ($plainTextToken): void {
            $token = ApiToken::query()->firstOrFail();
            $this->assertNotSame($plainTextToken, $token->token_hash);
            $this->assertSame(hash('sha256', explode('|', $plainTextToken, 2)[1]), $token->token_hash);
        });
    }

    public function test_the_created_token_works_against_the_api(): void
    {
        $this->actingAs($this->owner);

        $this->post($this->url(), [
            'name' => 'ERP sync',
            'abilities' => [ApiToken::ABILITY_ORDERS_READ],
        ]);

        $plainTextToken = $this->get($this->url())->viewData('page')['props']['plainTextToken'];

        $this->getJson('/api/v1/orders', $this->bearer($plainTextToken))->assertOk();
        $this->getJson('/api/v1/invoices', $this->bearer($plainTextToken))->assertStatus(403);
    }

    public function test_a_token_needs_a_name_and_at_least_one_known_scope(): void
    {
        $this->actingAs($this->owner)->post($this->url(), ['abilities' => []])
            ->assertSessionHasErrors(['name', 'abilities']);

        $this->actingAs($this->owner)->post($this->url(), [
            'name' => 'Everything',
            'abilities' => ['*'],
        ])->assertSessionHasErrors('abilities.0');

        TenantContext::run($this->tenant, fn () => $this->assertSame(0, ApiToken::query()->count()));
    }

    public function test_an_expiry_in_the_past_is_refused(): void
    {
        $this->actingAs($this->owner)->post($this->url(), [
            'name' => 'Already stale',
            'abilities' => [ApiToken::ABILITY_ORDERS_READ],
            'expires_at' => Carbon::now()->subDay()->toDateTimeString(),
        ])->assertSessionHasErrors('expires_at');
    }

    public function test_revoking_keeps_the_row_and_kills_the_key(): void
    {
        [$apiToken, $plainTextToken] = $this->issueTokenRow($this->tenant, $this->owner, [ApiToken::ABILITY_ORDERS_READ]);

        $this->getJson('/api/v1/orders', $this->bearer($plainTextToken))->assertOk();

        $this->actingAs($this->owner)->delete($this->url('/'.$apiToken->getKey()))
            ->assertRedirect($this->url());

        $this->getJson('/api/v1/orders', $this->bearer($plainTextToken))->assertStatus(401);

        TenantContext::run($this->tenant, function () use ($apiToken): void {
            $stored = ApiToken::query()->find($apiToken->getKey());

            $this->assertNotNull($stored, 'The row stays for the audit trail.');
            $this->assertNotNull($stored->revoked_at);
        });

        $this->actingAs($this->owner)->get($this->url())
            ->assertInertia(fn (Assert $page) => $page->where('tokens.0.status', 'revoked'));
    }

    public function test_revoking_twice_is_harmless(): void
    {
        [$apiToken] = $this->issueTokenRow($this->tenant, $this->owner, [ApiToken::ABILITY_ORDERS_READ]);

        $this->actingAs($this->owner)->delete($this->url('/'.$apiToken->getKey()))->assertRedirect();
        $this->actingAs($this->owner)->delete($this->url('/'.$apiToken->getKey()))->assertRedirect();
    }

    public function test_a_token_of_another_workspace_answers_404_not_403(): void
    {
        $other = $this->makeTenant('northgate', 'Northgate Electrical Distribution');
        $otherOwner = $this->makeMember($other, 'owner@northgate.test', Permissions::OWNER);
        [$otherToken] = $this->issueTokenRow($other, $otherOwner, [ApiToken::ABILITY_ORDERS_READ]);

        $this->actingAs($this->owner)
            ->delete($this->url('/'.$otherToken->getKey()))
            ->assertNotFound();

        TenantContext::run($other, fn () => $this->assertNull(
            ApiToken::query()->find($otherToken->getKey())?->revoked_at,
        ));
    }

    public function test_the_list_never_shows_another_workspaces_tokens(): void
    {
        $other = $this->makeTenant('northgate', 'Northgate Electrical Distribution');
        $otherOwner = $this->makeMember($other, 'owner@northgate.test', Permissions::OWNER);
        $this->issueTokenRow($other, $otherOwner, [ApiToken::ABILITY_ORDERS_READ]);
        $this->issueTokenRow($this->tenant, $this->owner, [ApiToken::ABILITY_ORDERS_READ]);

        $this->actingAs($this->owner)->get($this->url())
            ->assertInertia(fn (Assert $page) => $page->has('tokens', 1));
    }
}
