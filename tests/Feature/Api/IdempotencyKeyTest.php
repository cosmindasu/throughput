<?php

namespace Tests\Feature\Api;

use App\Models\Account;
use App\Models\ApiToken;
use App\Models\IdempotencyKey;
use App\Models\Order;
use App\Models\StockMovement;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Support\Permissions;
use Illuminate\Support\Carbon;
use Tests\Feature\Api\Concerns\IssuesApiTokens;
use Tests\TestCase;

/**
 * FR-API-03 / §18.4 și US-API-02 — „primesc de fiecare dată același răspuns (aceeași
 * comandă creată o singură dată), nu două comenzi".
 */
class IdempotencyKeyTest extends TestCase
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
            ApiToken::ABILITY_ORDERS_WRITE,
            ApiToken::ABILITY_INVENTORY_WRITE,
        ]);
    }

    public function test_the_header_is_required(): void
    {
        $response = $this->postJson('/api/v1/orders', [
            'account_id' => $this->account->getKey(),
        ], $this->bearer($this->token));

        $response->assertStatus(400);
        $this->assertStringContainsString('Idempotency-Key', $response->json('message'));

        TenantContext::run($this->tenant, fn () => $this->assertSame(0, Order::query()->count()));
    }

    public function test_the_same_key_with_the_same_body_replays_the_first_response(): void
    {
        $payload = ['account_id' => $this->account->getKey(), 'notes' => 'From the ERP'];
        $headers = $this->bearer($this->token) + ['Idempotency-Key' => 'erp-2026-09-20-0001'];

        $first = $this->postJson('/api/v1/orders', $payload, $headers);
        $first->assertStatus(201);

        $second = $this->postJson('/api/v1/orders', $payload, $headers);

        $second->assertStatus(201);
        $second->assertHeader('Idempotent-Replay', 'true');
        $this->assertSame($first->json('data.id'), $second->json('data.id'));

        // Aceleași chei și aceleași valori — dar comparate DUPĂ sortare: răspunsul e
        // păstrat într-o coloană `jsonb`, iar PostgreSQL nu conservă ordinea cheilor
        // într-un obiect JSON (o normalizează, prin design). Garanția lui §18.4 e „același
        // răspuns", adică același OBIECT JSON, nu aceeași secvență de octeți — pentru
        // orice client, ordinea cheilor unui obiect nu are semantică.
        $sorted = static function (array $data): array {
            ksort($data);

            return $data;
        };

        $this->assertSame($sorted($first->json('data')), $sorted($second->json('data')));

        TenantContext::run($this->tenant, fn () => $this->assertSame(1, Order::query()->count()));
    }

    public function test_the_key_is_not_shared_between_tenants(): void
    {
        $other = $this->makeTenant('northgate', 'Northgate Electrical Distribution');
        $otherOwner = $this->makeMember($other, 'owner@northgate.test', Permissions::OWNER);
        $otherAccount = $this->makeAccount($other, $otherOwner, 'Northgate Customer LLC');
        $otherToken = $this->issueToken($other, $otherOwner, [ApiToken::ABILITY_ORDERS_WRITE]);

        $key = ['Idempotency-Key' => 'shared-key-value'];

        $this->postJson('/api/v1/orders', ['account_id' => $this->account->getKey()], $this->bearer($this->token) + $key)
            ->assertStatus(201);

        // Unicitatea e pe `(tenant_id, key)`: două integrări care aleg din întâmplare
        // aceeași cheie nu trebuie să se blocheze reciproc.
        $this->postJson('/api/v1/orders', ['account_id' => $otherAccount->getKey()], $this->bearer($otherToken) + $key)
            ->assertStatus(201);

        TenantContext::run($this->tenant, fn () => $this->assertSame(1, Order::query()->count()));
        TenantContext::run($other, fn () => $this->assertSame(1, Order::query()->count()));
    }

    public function test_the_same_key_with_a_different_body_is_refused(): void
    {
        $headers = $this->bearer($this->token) + ['Idempotency-Key' => 'erp-2026-09-20-0002'];

        $this->postJson('/api/v1/orders', ['account_id' => $this->account->getKey(), 'notes' => 'A'], $headers)
            ->assertStatus(201);

        $response = $this->postJson('/api/v1/orders', ['account_id' => $this->account->getKey(), 'notes' => 'B'], $headers);

        $response->assertStatus(422);
        // Mesajul literal cerut de §18.4.
        $this->assertSame('Idempotency-Key reused with a different payload', $response->json('message'));

        TenantContext::run($this->tenant, fn () => $this->assertSame(1, Order::query()->count()));
    }

    public function test_the_same_fields_in_a_different_order_are_the_same_request(): void
    {
        $headers = $this->bearer($this->token) + ['Idempotency-Key' => 'erp-2026-09-20-0003'];

        $first = $this->postJson('/api/v1/orders', [
            'account_id' => $this->account->getKey(),
            'notes' => 'Reordered',
        ], $headers);
        $first->assertStatus(201);

        // Orice client HTTP poate serializa câmpurile în altă ordine; a numi asta „payload
        // diferit" ar transforma un retry legitim într-o eroare.
        $second = $this->postJson('/api/v1/orders', [
            'notes' => 'Reordered',
            'account_id' => $this->account->getKey(),
        ], $headers);

        $second->assertStatus(201);
        $second->assertHeader('Idempotent-Replay', 'true');
    }

    public function test_a_rejected_request_does_not_burn_the_key(): void
    {
        $headers = $this->bearer($this->token) + ['Idempotency-Key' => 'erp-2026-09-20-0004'];

        // Payload invalid → `ValidationException` → tranzacția cererii se derulează, deci
        // revendicarea cheii dispare odată cu ea.
        $this->postJson('/api/v1/orders', ['account_id' => 'not-an-id'], $headers)->assertStatus(422);

        TenantContext::run($this->tenant, fn () => $this->assertSame(0, IdempotencyKey::query()->count()));

        // Aceeași cheie, corp corectat: trece, nu „reused with a different payload".
        $this->postJson('/api/v1/orders', ['account_id' => $this->account->getKey()], $headers)
            ->assertStatus(201);
    }

    public function test_the_key_expires_after_24_hours(): void
    {
        $payload = ['account_id' => $this->account->getKey()];
        $headers = $this->bearer($this->token) + ['Idempotency-Key' => 'erp-2026-09-20-0005'];

        $first = $this->postJson('/api/v1/orders', $payload, $headers);
        $first->assertStatus(201);

        $this->travelTo(Carbon::now()->addHours(IdempotencyKey::TTL_HOURS + 1));

        $second = $this->postJson('/api/v1/orders', $payload, $headers);

        $second->assertStatus(201);
        $second->assertHeaderMissing('Idempotent-Replay');
        $this->assertNotSame($first->json('data.id'), $second->json('data.id'));

        TenantContext::run($this->tenant, fn () => $this->assertSame(2, Order::query()->count()));
    }

    public function test_stock_movements_are_idempotent_too(): void
    {
        [$variant, $location] = $this->makeStockFixture($this->tenant, 10);

        $payload = [
            'variant_id' => $variant->getKey(),
            'location_id' => $location->getKey(),
            'delta' => 5,
            'reason' => StockMovement::REASON_RECEIPT,
        ];
        $headers = $this->bearer($this->token) + ['Idempotency-Key' => 'wms-receipt-77'];

        $this->postJson('/api/v1/stock-movements', $payload, $headers)->assertStatus(201);
        $this->postJson('/api/v1/stock-movements', $payload, $headers)
            ->assertStatus(201)
            ->assertHeader('Idempotent-Replay', 'true');

        TenantContext::run($this->tenant, function () use ($variant): void {
            $this->assertSame(1, StockMovement::query()->count());
            // Cel mai important număr din test: efectul s-a aplicat o singură dată.
            $this->assertSame(15, (int) $variant->inventoryLevels()->value('on_hand'));
        });
    }

    public function test_a_key_longer_than_the_column_is_refused_rather_than_truncated(): void
    {
        $response = $this->postJson('/api/v1/orders', [
            'account_id' => $this->account->getKey(),
        ], $this->bearer($this->token) + ['Idempotency-Key' => str_repeat('k', 300)]);

        $response->assertStatus(400);
    }
}
