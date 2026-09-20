<?php

namespace Tests\Feature\Api;

use App\Enums\OrderStatus;
use App\Models\Account;
use App\Models\ApiToken;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\StockMovement;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Support\Permissions;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CreatesPipelines;
use Tests\Feature\Api\Concerns\IssuesApiTokens;
use Tests\TestCase;

/**
 * Suprafața API-ului v1 (specs.md §18) — un caz fericit și cel puțin o regulă de business
 * per resursă. Regulile în sine (numerotarea facturii, pragul de stoc, pipeline-ul
 * implicit) au deja teste proprii pe acțiuni; aici se verifică doar că API-ul le
 * REUTILIZEAZĂ, în loc să le rescrie.
 */
class ApiEndpointsTest extends TestCase
{
    use CreatesPipelines, IssuesApiTokens;

    private Tenant $tenant;

    private User $owner;

    private Account $account;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->tenant = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $this->owner = $this->makeMember($this->tenant, 'owner@throughput.dev', Permissions::OWNER);
        $this->account = $this->makeAccount($this->tenant, $this->owner);

        $this->token = $this->issueToken($this->tenant, $this->owner, ApiToken::allowedAbilities());
    }

    public function test_accounts_can_be_listed_and_fetched(): void
    {
        $this->getJson('/api/v1/accounts', $this->bearer($this->token))
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Northwind Industrial Supply LLC')
            ->assertJsonPath('meta.total', 1);

        $this->getJson('/api/v1/accounts/'.$this->account->getKey(), $this->bearer($this->token))
            ->assertOk()
            ->assertJsonPath('data.creditTerms', 'net_30');
    }

    public function test_a_contact_can_be_created_and_read_back(): void
    {
        $response = $this->postJson('/api/v1/contacts', [
            'account_id' => $this->account->getKey(),
            'first_name' => 'Marta',
            'last_name' => 'Iversen',
            'email' => 'marta@northwind.test',
            'is_primary' => true,
        ], $this->bearer($this->token));

        $response->assertStatus(201);
        $response->assertJsonPath('data.firstName', 'Marta');
        $response->assertJsonPath('data.isPrimary', true);

        $id = $response->json('data.id');

        $this->getJson('/api/v1/contacts/'.$id, $this->bearer($this->token))
            ->assertOk()
            ->assertJsonPath('data.email', 'marta@northwind.test');

        TenantContext::run($this->tenant, fn () => $this->assertSame(1, Contact::query()->count()));
    }

    public function test_a_primary_contact_still_needs_an_account(): void
    {
        // Regula e a lui `StoreContactRequest`, reutilizat din fluxul web — API-ul nu o
        // rescrie, deci nu poate diverge de el.
        $this->postJson('/api/v1/contacts', [
            'first_name' => 'No',
            'last_name' => 'Account',
            'is_primary' => true,
        ], $this->bearer($this->token))
            ->assertStatus(422)
            ->assertJsonValidationErrors('is_primary');
    }

    public function test_a_deal_starts_on_the_first_stage_of_the_default_pipeline(): void
    {
        TenantContext::run($this->tenant, fn () => $this->makeDefaultPipeline($this->tenant));

        $response = $this->postJson('/api/v1/deals', [
            'account_id' => $this->account->getKey(),
            'title' => 'Fastener framework agreement',
            'value' => 48000,
        ], $this->bearer($this->token));

        $response->assertStatus(201);
        $response->assertJsonPath('data.status', Deal::STATUS_OPEN);
        $this->assertNotNull($response->json('data.stageId'));
        $this->assertNotNull($response->json('data.stageName'));

        $this->getJson('/api/v1/deals', $this->bearer($this->token))
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
    }

    public function test_an_order_is_created_as_a_draft_with_its_lines(): void
    {
        [$variant] = $this->makeStockFixture($this->tenant);

        $response = $this->postJson('/api/v1/orders', [
            'account_id' => $this->account->getKey(),
            'lines' => [
                ['variant_id' => $variant->getKey(), 'quantity' => 4],
            ],
        ], $this->bearer($this->token) + ['Idempotency-Key' => 'order-with-lines-1']);

        $response->assertStatus(201);
        // BR-ORD-02 — numărul de comandă se alocă la confirmare, nu la creare.
        $response->assertJsonPath('data.status', OrderStatus::Draft->value);
        $response->assertJsonPath('data.orderNumber', null);
        $response->assertJsonPath('data.lines.0.quantity', 4);
        // `assertEquals`, nu `assertSame`: JSON are un singur tip numeric, iar
        // `json_encode(50.0)` produce `50` (serialize_precision=-1, reprezentarea cea
        // mai scurtă care face roundtrip). Schema OpenAPI declară `number`, care
        // acceptă ambele forme — nu e o pierdere de precizie, e notația JSON.
        $this->assertEquals(50.0, $response->json('data.grandTotal'));

        $this->getJson('/api/v1/orders/'.$response->json('data.id'), $this->bearer($this->token))
            ->assertOk()
            ->assertJsonPath('data.lines.0.sku', $variant->sku);
    }

    public function test_an_invoice_can_only_be_raised_from_a_confirmed_or_fulfilled_order(): void
    {
        $draft = TenantContext::run($this->tenant, function (): Order {
            $order = new Order([
                'account_id' => $this->account->getKey(),
                'owner_user_id' => $this->owner->getKey(),
                'status' => OrderStatus::Draft,
                'currency' => 'USD',
                'subtotal' => 100,
                'discount_total' => 0,
                'shipping_total' => 0,
                'grand_total' => 100,
            ]);
            $order->created_by = $this->owner->getKey();
            $order->save();

            return $order;
        });

        $this->postJson('/api/v1/invoices', [
            'order_id' => $draft->getKey(),
        ], $this->bearer($this->token) + ['Idempotency-Key' => 'invoice-from-draft'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');

        $confirmed = $this->makeConfirmedOrder($this->tenant, $this->account, $this->owner, 750.0);

        $response = $this->postJson('/api/v1/invoices', [
            'order_id' => $confirmed->getKey(),
        ], $this->bearer($this->token) + ['Idempotency-Key' => 'invoice-from-confirmed']);

        $response->assertStatus(201);
        $response->assertJsonPath('data.status', Invoice::STATUS_DRAFT);
        $response->assertJsonPath('data.pdfStatus', Invoice::PDF_STATUS_PENDING);
        $this->assertEquals(750.0, $response->json('data.total'));
        $this->assertNotNull($response->json('data.invoiceNumber'));

        // `pdfPath` nu face parte din contract — e o cale de pe disc, nu date de business.
        $this->assertArrayNotHasKey('pdfPath', $response->json('data'));

        $this->getJson('/api/v1/invoices', $this->bearer($this->token))
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
    }

    public function test_stock_levels_and_movements(): void
    {
        [$variant, $location] = $this->makeStockFixture($this->tenant, 12);

        $this->getJson('/api/v1/inventory', $this->bearer($this->token))
            ->assertOk()
            ->assertJsonPath('data.0.onHand', 12)
            ->assertJsonPath('data.0.available', 12)
            ->assertJsonPath('data.0.sku', $variant->sku);

        $this->postJson('/api/v1/stock-movements', [
            'variant_id' => $variant->getKey(),
            'location_id' => $location->getKey(),
            'delta' => -3,
            'reason' => StockMovement::REASON_ADJUSTMENT,
            'note' => 'Damaged in the rack',
        ], $this->bearer($this->token) + ['Idempotency-Key' => 'adjust-1'])
            ->assertStatus(201)
            ->assertJsonPath('data.delta', -3);

        $this->getJson('/api/v1/inventory', $this->bearer($this->token))
            ->assertOk()
            ->assertJsonPath('data.0.onHand', 9);

        $this->getJson('/api/v1/stock-movements', $this->bearer($this->token))
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
    }

    public function test_an_adjustment_without_a_note_is_refused(): void
    {
        [$variant, $location] = $this->makeStockFixture($this->tenant);

        $this->postJson('/api/v1/stock-movements', [
            'variant_id' => $variant->getKey(),
            'location_id' => $location->getKey(),
            'delta' => -1,
            'reason' => StockMovement::REASON_ADJUSTMENT,
        ], $this->bearer($this->token) + ['Idempotency-Key' => 'adjust-no-note'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('note');
    }

    public function test_reasons_written_by_internal_flows_are_not_accepted_over_the_api(): void
    {
        [$variant, $location] = $this->makeStockFixture($this->tenant);

        foreach ([StockMovement::REASON_SALE, StockMovement::REASON_TRANSFER, StockMovement::REASON_RETURN] as $reason) {
            $this->postJson('/api/v1/stock-movements', [
                'variant_id' => $variant->getKey(),
                'location_id' => $location->getKey(),
                'delta' => -1,
                'reason' => $reason,
                'note' => 'nope',
            ], $this->bearer($this->token) + ['Idempotency-Key' => 'reason-'.$reason])
                ->assertStatus(422)
                ->assertJsonValidationErrors('reason');
        }
    }

    public function test_a_movement_that_would_take_stock_below_zero_is_refused(): void
    {
        [$variant, $location] = $this->makeStockFixture($this->tenant, 2);

        $this->postJson('/api/v1/stock-movements', [
            'variant_id' => $variant->getKey(),
            'location_id' => $location->getKey(),
            'delta' => -5,
            'reason' => StockMovement::REASON_ADJUSTMENT,
            'note' => 'Too many',
        ], $this->bearer($this->token) + ['Idempotency-Key' => 'adjust-below-zero'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('delta');
    }

    public function test_pagination_is_capped(): void
    {
        $this->getJson('/api/v1/accounts?perPage=5000', $this->bearer($this->token))
            ->assertOk()
            ->assertJsonPath('meta.perPage', 100);

        $this->getJson('/api/v1/accounts?perPage=0', $this->bearer($this->token))
            ->assertOk()
            ->assertJsonPath('meta.perPage', 1);
    }

    public function test_an_anonymized_contact_never_appears_in_the_api(): void
    {
        $contact = TenantContext::run($this->tenant, function (): Contact {
            $contact = new Contact([
                'account_id' => $this->account->getKey(),
                'first_name' => 'Erased',
                'last_name' => 'Person',
            ]);
            $contact->created_by = $this->owner->getKey();
            $contact->save();
            $contact->forceFill(['anonymized_at' => now()])->save();

            return $contact;
        });

        $this->getJson('/api/v1/contacts', $this->bearer($this->token))
            ->assertOk()
            ->assertJsonPath('meta.total', 0);

        $this->getJson('/api/v1/contacts/'.$contact->getKey(), $this->bearer($this->token))
            ->assertStatus(404);
    }
}
