<?php

namespace Tests\Feature\Api;

use App\Models\Account;
use App\Models\ApiToken;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Support\Permissions;
use Illuminate\Testing\TestResponse;
use Laravel\Cashier\Subscription;
use Stripe\Subscription as StripeSubscription;
use Tests\Feature\Api\Concerns\IssuesApiTokens;
use Tests\TestCase;

/**
 * specs.md §12.2 — degradarea pe 3 trepte, pe API-ul public.
 *
 * §18 nu pomenește abonamentul, iar §12.2 nu pomenește API-ul: golul dintre cele două
 * secțiuni e semnalat în raportul lotului. Comportamentul ales aici e cel care face
 * regula ADEVĂRATĂ — „creare/editare/ștergere blocate, indiferent de rol" n-ar fi însemnat
 * nimic dacă aceleași scrieri treceau printr-un jeton.
 */
class ApiSubscriptionAccessTest extends TestCase
{
    use IssuesApiTokens;

    private Tenant $tenant;

    private User $owner;

    private Account $account;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $this->owner = $this->makeMember($this->tenant, 'owner@throughput.dev', Permissions::OWNER);
        $this->account = $this->makeAccount($this->tenant, $this->owner);

        $this->token = $this->issueToken($this->tenant, $this->owner, [
            ApiToken::ABILITY_ORDERS_READ,
            ApiToken::ABILITY_ORDERS_WRITE,
        ]);
    }

    private function setSubscriptionStatus(string $stripeStatus): void
    {
        TenantContext::run($this->tenant, function () use ($stripeStatus): void {
            Subscription::query()->updateOrCreate(
                ['user_id' => $this->tenant->getKey(), 'stripe_id' => 'sub_api_test'],
                ['type' => 'default', 'stripe_status' => $stripeStatus],
            );
        });

        $this->clearDatabaseTenantContext();
    }

    private function createOrder(): TestResponse
    {
        return $this->postJson('/api/v1/orders', [
            'account_id' => $this->account->getKey(),
        ], $this->bearer($this->token) + ['Idempotency-Key' => 'subscription-'.uniqid()]);
    }

    public function test_past_due_changes_nothing(): void
    {
        // BR-BILL-03 — Stripe încă reîncearcă; eșecul nu e definitiv, accesul rămâne complet.
        $this->setSubscriptionStatus(StripeSubscription::STATUS_PAST_DUE);

        $this->getJson('/api/v1/orders', $this->bearer($this->token))->assertOk();
        $this->createOrder()->assertStatus(201);
    }

    public function test_unpaid_leaves_reads_working_and_refuses_writes(): void
    {
        $this->setSubscriptionStatus(StripeSubscription::STATUS_UNPAID);

        $this->getJson('/api/v1/orders', $this->bearer($this->token))->assertOk();

        $response = $this->createOrder();

        $response->assertStatus(403);
        $this->assertStringContainsString('read-only', $response->json('message'));

        TenantContext::run($this->tenant, fn () => $this->assertSame(0, Order::query()->count()));
    }

    public function test_canceled_closes_the_api_entirely(): void
    {
        $this->setSubscriptionStatus(StripeSubscription::STATUS_CANCELED);

        $read = $this->getJson('/api/v1/orders', $this->bearer($this->token));

        $read->assertStatus(403);
        // JSON, nu un redirect Inertia spre pagina de billing: un client de API nu poate
        // urma un redirect către un ecran React.
        $read->assertHeader('Content-Type', 'application/json');
        $this->assertStringContainsString('cancelled', $read->json('message'));

        $this->createOrder()->assertStatus(403);
    }
}
