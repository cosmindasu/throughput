<?php

namespace Tests\Feature\Api;

use App\Actions\Invoices\CreateInvoiceAction;
use App\Models\Account;
use App\Models\ApiToken;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Pipeline;
use App\Models\Stage;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Support\Permissions;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesPipelines;
use Tests\Feature\Api\Concerns\IssuesApiTokens;
use Tests\TestCase;

/**
 * §18.5 — mitigarea BOLA, testul dedicat cerut explicit de specificație și de plan §11:
 * „token-uri din 2 tenanți diferite, cereri încrucișate pe ID-uri valide ale celuilalt
 * tenant → toate trebuie să returneze `404` (nu `403` — nu confirmăm nici măcar existența
 * resursei)".
 *
 * Distincția `404` vs `403` e întregul punct. Un `403` ar fi tot un refuz corect, dar ar
 * răspunde la întrebarea „există obiectul cu ID-ul ăsta?" — adică ar transforma o listă de
 * ID-uri furate într-un oracol de existență. De aceea aserțiunea de mai jos e pe cod, nu
 * pe „nu 200".
 */
class ApiCrossTenantIsolationTest extends TestCase
{
    use CreatesPipelines, IssuesApiTokens;

    private Tenant $marlin;

    private Tenant $northgate;

    private User $marlinOwner;

    private User $northgateOwner;

    /** @var array<string, string> resursă → ID valid, dar al lui Northgate */
    private array $northgateIds = [];

    private string $marlinToken;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->marlin = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $this->northgate = $this->makeTenant('northgate', 'Northgate Electrical Distribution');

        $this->marlinOwner = $this->makeMember($this->marlin, 'owner@marlin.test', Permissions::OWNER);
        $this->northgateOwner = $this->makeMember($this->northgate, 'owner@northgate.test', Permissions::OWNER);

        $northgateAccount = $this->makeAccount($this->northgate, $this->northgateOwner, 'Northgate Customer LLC');

        TenantContext::run($this->northgate, function () use ($northgateAccount): void {
            $this->makeDefaultPipeline($this->northgate);

            $contact = new Contact([
                'account_id' => $northgateAccount->getKey(),
                'first_name' => 'Dana',
                'last_name' => 'Ruiz',
                'email' => 'dana@northgate.test',
            ]);
            $contact->created_by = $this->northgateOwner->getKey();
            $contact->save();

            $deal = new Deal([
                'account_id' => $northgateAccount->getKey(),
                'pipeline_id' => Pipeline::query()->value('id'),
                'stage_id' => Stage::query()->orderBy('position')->value('id'),
                'owner_user_id' => $this->northgateOwner->getKey(),
                'title' => 'Switchgear refit',
                'value' => 25000,
                'currency' => 'USD',
                'status' => Deal::STATUS_OPEN,
            ]);
            $deal->created_by = $this->northgateOwner->getKey();
            $deal->save();

            $this->northgateIds['accounts'] = $northgateAccount->getKey();
            $this->northgateIds['contacts'] = $contact->getKey();
            $this->northgateIds['deals'] = $deal->getKey();
        });

        $order = $this->makeConfirmedOrder($this->northgate, $northgateAccount, $this->northgateOwner, 1200.0);
        $this->northgateIds['orders'] = $order->getKey();

        TenantContext::run($this->northgate, function () use ($order): void {
            $this->northgateIds['invoices'] = (new CreateInvoiceAction)->execute($order)->getKey();
        });

        // Jetonul lui Marlin, cu TOATE scopurile de citire: nimic nu e refuzat din lipsă
        // de scop, deci orice `404` de mai jos e izolare de tenant, nu altceva.
        $this->marlinToken = $this->issueToken($this->marlin, $this->marlinOwner, [
            ApiToken::ABILITY_ACCOUNTS_READ,
            ApiToken::ABILITY_CONTACTS_READ,
            ApiToken::ABILITY_DEALS_READ,
            ApiToken::ABILITY_ORDERS_READ,
            ApiToken::ABILITY_INVOICES_READ,
            ApiToken::ABILITY_INVENTORY_READ,
        ]);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function crossTenantResources(): array
    {
        return [
            'accounts' => ['accounts'],
            'contacts' => ['contacts'],
            'deals' => ['deals'],
            'orders' => ['orders'],
            'invoices' => ['invoices'],
        ];
    }

    #[DataProvider('crossTenantResources')]
    public function test_a_valid_id_from_another_tenant_answers_404_not_403(string $resource): void
    {
        $id = $this->northgateIds[$resource];

        $response = $this->getJson("/api/v1/{$resource}/{$id}", $this->bearer($this->marlinToken));

        $response->assertStatus(404);
        $this->assertNotSame(403, $response->status(), 'A 403 would confirm the record exists (§18.5).');
    }

    public function test_the_same_ids_are_reachable_with_the_owning_tenants_token(): void
    {
        $northgateToken = $this->issueToken($this->northgate, $this->northgateOwner, [
            ApiToken::ABILITY_ACCOUNTS_READ,
            ApiToken::ABILITY_CONTACTS_READ,
            ApiToken::ABILITY_DEALS_READ,
            ApiToken::ABILITY_ORDERS_READ,
            ApiToken::ABILITY_INVOICES_READ,
        ]);

        // Contraproba: fără ea, testul de mai sus ar trece și dacă ID-urile ar fi greșite
        // sau rutele inexistente — adică din motivul complet greșit.
        foreach (array_keys(self::crossTenantResources()) as $resource) {
            $id = $this->northgateIds[$resource];

            $this->getJson("/api/v1/{$resource}/{$id}", $this->bearer($northgateToken))
                ->assertOk()
                ->assertJsonPath('data.id', $id);
        }
    }

    public function test_lists_never_contain_another_tenants_rows(): void
    {
        $this->makeAccount($this->marlin, $this->marlinOwner, 'Marlin Customer LLC');

        $response = $this->getJson('/api/v1/accounts', $this->bearer($this->marlinToken));

        $response->assertOk();
        $this->assertSame(1, $response->json('meta.total'));
        $this->assertSame(['Marlin Customer LLC'], array_column($response->json('data'), 'name'));
    }

    public function test_a_write_cannot_attach_a_record_to_another_tenants_parent(): void
    {
        $writeToken = $this->issueToken($this->marlin, $this->marlinOwner, [ApiToken::ABILITY_ORDERS_WRITE]);

        // ID valid, dar al lui Northgate: validarea îl respinge cu 422 (mesaj util), nu
        // creează o comandă legată peste graniță și nu ajunge la RLS cu un 500 opac.
        $response = $this->postJson('/api/v1/orders', [
            'account_id' => $this->northgateIds['accounts'],
        ], $this->bearer($writeToken) + ['Idempotency-Key' => 'cross-tenant-write-1']);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('account_id');

        TenantContext::run($this->marlin, fn () => $this->assertSame(0, Order::query()->count()));
        TenantContext::run($this->northgate, fn () => $this->assertSame(1, Order::query()->count()));
    }

    public function test_an_unknown_id_answers_404_as_well(): void
    {
        $this->getJson('/api/v1/orders/01JQZZZZZZZZZZZZZZZZZZZZZZ', $this->bearer($this->marlinToken))
            ->assertStatus(404);
    }

    public function test_the_invoice_of_another_tenant_is_not_reachable_through_its_order(): void
    {
        $writeToken = $this->issueToken($this->marlin, $this->marlinOwner, [ApiToken::ABILITY_INVOICES_WRITE]);

        $response = $this->postJson('/api/v1/invoices', [
            'order_id' => $this->northgateIds['orders'],
        ], $this->bearer($writeToken) + ['Idempotency-Key' => 'cross-tenant-invoice-1']);

        $response->assertStatus(422);

        TenantContext::run($this->northgate, fn () => $this->assertSame(
            1,
            Invoice::query()->where('order_id', $this->northgateIds['orders'])->count(),
        ));
    }

    public function test_the_marlin_account_is_not_visible_to_northgate_either(): void
    {
        $marlinAccount = $this->makeAccount($this->marlin, $this->marlinOwner, 'Marlin Only LLC');
        $northgateToken = $this->issueToken($this->northgate, $this->northgateOwner, [ApiToken::ABILITY_ACCOUNTS_READ]);

        $this->getJson('/api/v1/accounts/'.$marlinAccount->getKey(), $this->bearer($northgateToken))
            ->assertStatus(404);

        $this->assertInstanceOf(Account::class, $marlinAccount);
    }
}
