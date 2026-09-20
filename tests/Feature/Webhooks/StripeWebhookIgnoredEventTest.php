<?php

namespace Tests\Feature\Webhooks;

use App\Models\Tenant;
use App\Models\WebhookEvent;
use App\Support\Permissions;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\SignsStripeWebhooks;
use Tests\TestCase;

/**
 * §12.3 + decizia proprietarului din 2026-09-20 — sandbox-ul Stripe rămâne ÎMPĂRȚIT cu alt
 * proiect, deci acest endpoint primește, cu semnătură perfect validă, evenimente care nu ne
 * privesc. Ele NU sunt eșecuri: `status = ignored`, nu `failed`.
 *
 * Coada `database` (`.ai/rules/tenancy.md`) — se verifică pe tabela `jobs` reală că NIMIC nu
 * s-a dispecerizat, nu printr-un `Queue::fake()` care ar ascunde exact ce contează aici.
 */
class StripeWebhookIgnoredEventTest extends TestCase
{
    use SignsStripeWebhooks;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $this->makeMember($this->tenant, 'owner@throughput.dev', Permissions::OWNER);
        $this->tenant->forceFill(['stripe_id' => 'cus_marlin_test'])->save();

        $this->clearDatabaseTenantContext();
    }

    /** Cerința literală a lotului: semnătură validă + `customer` nemapat → 200, rând `ignored`, NICIUN job. */
    public function test_a_valid_event_for_another_projects_customer_is_ignored_without_dispatching_anything(): void
    {
        $event = $this->stripeEvent('customer.subscription.updated', [
            'id' => 'sub_from_the_other_project',
            'customer' => 'cus_belongs_to_another_project',
            'status' => 'active',
        ], eventId: 'evt_shared_sandbox_1');

        $this->postStripeWebhook($event)->assertOk();

        $stored = WebhookEvent::query()->sole();

        $this->assertSame(WebhookEvent::STATUS_IGNORED, $stored->status);
        $this->assertSame(
            0,
            DB::table('jobs')->count(),
            'Un eveniment care nu ne privește nu are tenant în al cărui context să ruleze — deci niciun job.',
        );

        // `error_message` EXPLICĂ, nu acuză: spune de ce e normal (sandbox partajat) și
        // păstrează id-ul de customer, singurul reper operațional util.
        $this->assertStringContainsString('cus_belongs_to_another_project', (string) $stored->error_message);
        $this->assertStringContainsString('shared with another project', (string) $stored->error_message);
    }

    /** Un payload fără `data.object.customer` deloc: tot „nu ne privește", nu „a eșuat ceva". */
    public function test_an_event_without_any_customer_reference_is_ignored_too(): void
    {
        $event = $this->stripeEvent('price.created', ['id' => 'price_from_elsewhere'], eventId: 'evt_shared_sandbox_2');

        $this->postStripeWebhook($event)->assertOk();

        $stored = WebhookEvent::query()->sole();

        $this->assertSame(WebhookEvent::STATUS_IGNORED, $stored->status);
        $this->assertStringContainsString('no data.object.customer', (string) $stored->error_message);
        $this->assertSame(0, DB::table('jobs')->count());
    }

    /** Regresie: evenimentul NOSTRU nu devine `ignored` — ramura nouă nu are voie să înghită traficul real. */
    public function test_an_event_for_a_mapped_customer_still_dispatches_the_processing_job(): void
    {
        $event = $this->stripeEvent('customer.subscription.updated', [
            'id' => 'sub_marlin_test',
            'customer' => 'cus_marlin_test',
            'status' => 'active',
            'items' => ['data' => [
                ['id' => 'si_1', 'price' => ['id' => 'price_pro', 'product' => 'prod_pro'], 'quantity' => 1],
            ]],
        ], eventId: 'evt_ours_1');

        $this->postStripeWebhook($event)->assertOk();

        $this->assertSame(WebhookEvent::STATUS_RECEIVED, WebhookEvent::query()->sole()->status);
        $this->assertSame(1, DB::table('jobs')->count());
    }

    /**
     * CHECK-ul din PostgreSQL, nu doar constanta din PHP: `status` e un `enum` Laravel,
     * adică `varchar` + `webhook_events_status_check`. Dacă migrația n-ar fi rulat, scrierea
     * de mai sus ar fi picat cu `SQLSTATE[23514]` — testul ăsta e garda explicită, ca un
     * `migrate:fresh` fără migrația nouă să nu treacă neobservat.
     */
    public function test_the_database_accepts_the_ignored_status(): void
    {
        $definition = DB::selectOne(
            "select pg_get_constraintdef(oid) as def from pg_constraint where conname = 'webhook_events_status_check'"
        );

        $this->assertNotNull($definition, 'Constrângerea webhook_events_status_check a dispărut.');
        $this->assertStringContainsString("'ignored'", (string) $definition->def);
    }
}
