<?php

namespace Tests\Feature\Api;

use App\Models\Account;
use App\Models\ApiToken;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Support\Permissions;
use Tests\Feature\Api\Concerns\IssuesApiTokens;
use Tests\TestCase;

/**
 * FR-API-01 și livrabilul „Token API cu scope unic (`orders:read`) primește `403` explicit
 * la o cerere `orders:write`" (plan §11), plus regula pe care Gherkin-ul US-API-01 n-o
 * spune dar matricea §7.4 o impune: **scopul îngustează, nu lărgește**.
 */
class ApiScopeTest extends TestCase
{
    use IssuesApiTokens;

    private Tenant $tenant;

    private User $owner;

    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $this->owner = $this->makeMember($this->tenant, 'owner@throughput.dev', Permissions::OWNER);
        $this->account = $this->makeAccount($this->tenant, $this->owner);
    }

    public function test_a_read_only_token_is_refused_with_the_missing_scope_named(): void
    {
        $token = $this->issueToken($this->tenant, $this->owner, [ApiToken::ABILITY_ORDERS_READ]);

        $response = $this->postJson('/api/v1/orders', [
            'account_id' => $this->account->getKey(),
        ], $this->bearer($token) + ['Idempotency-Key' => 'scope-check-1']);

        $response->assertStatus(403);
        // Mesajul e cel din Gherkin-ul US-API-01, literal.
        $response->assertJson([
            'message' => 'Missing required scope: orders:write',
            'requiredScope' => ApiToken::ABILITY_ORDERS_WRITE,
        ]);

        TenantContext::run($this->tenant, fn () => $this->assertSame(0, Order::query()->count()));
    }

    public function test_the_same_token_can_still_read(): void
    {
        $token = $this->issueToken($this->tenant, $this->owner, [ApiToken::ABILITY_ORDERS_READ]);

        $this->getJson('/api/v1/orders', $this->bearer($token))->assertOk();
    }

    public function test_a_scope_for_another_resource_does_not_open_this_one(): void
    {
        $token = $this->issueToken($this->tenant, $this->owner, [ApiToken::ABILITY_ORDERS_READ]);

        $this->getJson('/api/v1/invoices', $this->bearer($token))
            ->assertStatus(403)
            ->assertJson(['requiredScope' => ApiToken::ABILITY_INVOICES_READ]);

        $this->getJson('/api/v1/contacts', $this->bearer($token))
            ->assertStatus(403)
            ->assertJson(['requiredScope' => ApiToken::ABILITY_CONTACTS_READ]);
    }

    /**
     * §7.4 — jetonul nu poate depăși rolul emitentului. Un Viewer nu are `orders.create`,
     * deci un jeton `orders:write` emis de el e refuzat de Policy, nu servit.
     *
     * Fără această regulă, orice rol care poate emite jetoane ar fi putut emite unul mai
     * puternic decât el — exact escaladarea pe care matricea o exclude. (Viewer-ul nu
     * poate EMITE jetoane azi; testul îl folosește totuși ca emitent, construind rândul
     * direct, fiindcă ce se verifică aici e că plafonul de rol se aplică LA CERERE, nu
     * doar la emitere: un membru retrogradat după emitere trebuie să piardă accesul.)
     */
    public function test_a_token_never_grants_more_than_the_role_of_the_member_who_issued_it(): void
    {
        $viewer = $this->makeMember($this->tenant, 'viewer@throughput.dev', Permissions::VIEWER);

        $token = $this->issueToken($this->tenant, $viewer, [
            ApiToken::ABILITY_ORDERS_READ,
            ApiToken::ABILITY_ORDERS_WRITE,
        ]);

        // Citirea e permisă unui Viewer (§7.4).
        $this->getJson('/api/v1/orders', $this->bearer($token))->assertOk();

        // Scrierea nu — și refuzul vine de la `OrderPolicy`, nu de la scop.
        $response = $this->postJson('/api/v1/orders', [
            'account_id' => $this->account->getKey(),
        ], $this->bearer($token) + ['Idempotency-Key' => 'viewer-write-1']);

        $response->assertStatus(403);
        $this->assertNull($response->json('requiredScope'), 'The refusal must come from the role, not from a missing scope.');

        TenantContext::run($this->tenant, fn () => $this->assertSame(0, Order::query()->count()));
    }
}
