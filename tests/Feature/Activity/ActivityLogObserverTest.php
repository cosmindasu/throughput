<?php

namespace Tests\Feature\Activity;

use App\Models\Account;
use App\Models\ActivityLog;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Variant;
use App\Providers\ActivityLogServiceProvider;
use App\Services\Tenancy\TenantContext;
use App\Support\Permissions;
use Database\Factories\AccountFactory;
use Database\Factories\ProductFactory;
use Database\Factories\VariantFactory;
use ReflectionMethod;
use Tests\TestCase;

/**
 * ADR-007, specs.md §17 — instrumentarea LIVE: observer → event → queued listener → rând
 * în `activity_log`. Testat pe Account (jsonb/array casts) și Variant (decimal, exact
 * scenariul US-AUD-01 — „prețul variantei a fost schimbat de 3 ori").
 */
class ActivityLogObserverTest extends TestCase
{
    private Tenant $marlin;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->marlin = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $this->owner = $this->makeMember($this->marlin, 'owner@throughput.dev', Permissions::OWNER);
        $this->clearDatabaseTenantContext();
        $this->actingAs($this->owner);
    }

    public function test_creating_a_model_writes_a_queued_activity_log_row_with_no_old_values(): void
    {
        $account = TenantContext::run($this->marlin, fn () => (new AccountFactory)->create([
            'name' => 'Acme Corp',
            'owner_user_id' => $this->owner->getKey(),
            'created_by' => $this->owner->getKey(),
        ]));

        $this->drainDefaultQueue();

        $log = $this->soleLogFor(Account::class, $account->getKey());

        $this->assertSame('created', $log->action);
        $this->assertSame($this->owner->getKey(), $log->user_id);
        $this->assertNull($log->old_values);
        $this->assertSame('Acme Corp', $log->new_values['name']);
        $this->assertIsString($log->ip_address);
        $this->assertIsString($log->user_agent);
    }

    public function test_updating_a_model_records_only_the_changed_fields(): void
    {
        $account = TenantContext::run($this->marlin, fn () => (new AccountFactory)->create([
            'name' => 'Acme Corp',
            'phone' => '(555) 000-0000',
            'owner_user_id' => $this->owner->getKey(),
            'created_by' => $this->owner->getKey(),
        ]));
        $this->drainDefaultQueue();

        TenantContext::run($this->marlin, function () use ($account): void {
            $account->update(['name' => 'Acme Corporation']);
        });
        $this->drainDefaultQueue();

        $log = $this->soleLogFor(Account::class, $account->getKey(), 'updated');

        $this->assertSame(['name' => 'Acme Corp'], $log->old_values);
        $this->assertSame(['name' => 'Acme Corporation'], $log->new_values);
        // BR-CRM (nu doar BR-AUD-01): un câmp NEschimbat („phone") nu apare în diff — §17.1
        // cere explicit „doar câmpurile modificate, nu tot rândul".
        $this->assertArrayNotHasKey('phone', $log->new_values);
    }

    /**
     * US-AUD-01, specs.md §17.3, textual: „prețul variantei HEX-BOLT-M8 a fost schimbat
     * de 3 ori în ultima lună ... văd 3 înregistrări, fiecare cu autorul, data, valoarea
     * veche și noua valoare a prețului."
     */
    public function test_three_successive_price_changes_on_a_variant_produce_three_ordered_log_rows(): void
    {
        $variant = TenantContext::run($this->marlin, function (): Variant {
            $product = (new ProductFactory)->create(['name' => 'Hex Bolt M8']);

            return (new VariantFactory)->create(['product_id' => $product->getKey(), 'price' => 10.00]);
        });
        $this->drainDefaultQueue();

        foreach ([12.50, 15.00, 9.99] as $price) {
            TenantContext::run($this->marlin, function () use ($variant, $price): void {
                $variant->update(['price' => $price]);
            });
            $this->drainDefaultQueue();
        }

        $logs = TenantContext::run(
            $this->marlin,
            fn () => ActivityLog::query()
                ->where('auditable_type', Variant::class)
                ->where('auditable_id', $variant->getKey())
                ->where('action', 'updated')
                ->orderBy('created_at')
                ->orderBy('id')
                ->get(),
        );

        $this->assertCount(3, $logs);
        $this->assertSame(['10.00', '12.50', '15.00'], $logs->pluck('old_values.price')->all());
        $this->assertSame(['12.50', '15.00', '9.99'], $logs->pluck('new_values.price')->all());
        $logs->each(fn (ActivityLog $log) => $this->assertSame($this->owner->getKey(), $log->user_id));
    }

    public function test_deleting_a_model_records_old_values_and_no_new_values(): void
    {
        $account = TenantContext::run($this->marlin, fn () => (new AccountFactory)->create([
            'name' => 'Acme Corp',
            'owner_user_id' => $this->owner->getKey(),
            'created_by' => $this->owner->getKey(),
        ]));
        $this->drainDefaultQueue();

        TenantContext::run($this->marlin, function () use ($account): void {
            $account->delete();
        });
        $this->drainDefaultQueue();

        $log = $this->soleLogFor(Account::class, $account->getKey(), 'deleted');

        $this->assertNull($log->new_values);
        $this->assertSame('Acme Corp', $log->old_values['name']);
    }

    /**
     * §17.1 — „doar câmpurile modificate". Un `touch()` schimbă exclusiv `updated_at`
     * (coloană TEHNICĂ, exclusă de `App\Support\Activity\ChangedAttributes`), deci nu are
     * ce înregistra ca modificare de business.
     */
    public function test_touching_a_model_without_a_business_change_writes_nothing(): void
    {
        $account = TenantContext::run($this->marlin, fn () => (new AccountFactory)->create([
            'owner_user_id' => $this->owner->getKey(),
            'created_by' => $this->owner->getKey(),
        ]));
        $this->drainDefaultQueue();
        $this->clearDatabaseTenantContext();

        $before = TenantContext::run(
            $this->marlin,
            fn () => ActivityLog::query()->where('auditable_type', Account::class)->where('auditable_id', $account->getKey())->count(),
        );

        TenantContext::run($this->marlin, function () use ($account): void {
            $account->touch();
        });
        $this->drainDefaultQueue();

        $after = TenantContext::run(
            $this->marlin,
            fn () => ActivityLog::query()->where('auditable_type', Account::class)->where('auditable_id', $account->getKey())->count(),
        );

        $this->assertSame($before, $after);
    }

    /**
     * Garda de regresie pentru lista de modele observate (`App\Providers\
     * ActivityLogServiceProvider`) — o schimbare tăcută a acestei liste (model uitat/
     * adăugat din greșeală) trebuie să pice un test, nu doar să se descopere manual.
     */
    public function test_the_provider_observes_exactly_the_entities_with_a_history_tab(): void
    {
        $method = new ReflectionMethod(ActivityLogServiceProvider::class, 'observedModels');
        $method->setAccessible(true);

        // Lista e cea din §17.3/FR-AUD-02 („cont, contact, deal, comandă, factură,
        // produs"). `Invoice` a intrat la integrarea Fazei 5, când modulul de facturare a
        // aterizat; `Payment` NU e pe listă, deliberat — nu apare în cerință, iar plățile
        // se citesc din secțiunea lor de pe pagina facturii.
        $this->assertSame(
            [Account::class, Contact::class, Deal::class, Product::class, Variant::class, Order::class, Invoice::class],
            $method->invoke(new ActivityLogServiceProvider($this->app)),
        );
    }

    private function soleLogFor(string $auditableType, string $auditableId, ?string $action = null): ActivityLog
    {
        return TenantContext::run($this->marlin, function () use ($auditableType, $auditableId, $action): ActivityLog {
            $query = ActivityLog::query()
                ->where('auditable_type', $auditableType)
                ->where('auditable_id', $auditableId);

            if ($action !== null) {
                $query->where('action', $action);
            }

            return $query->sole();
        });
    }

    private function drainDefaultQueue(): void
    {
        $this->clearDatabaseTenantContext();

        $this->artisan('queue:work', [
            '--stop-when-empty' => true,
            '--no-interaction' => true,
        ]);
    }
}
