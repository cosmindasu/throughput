<?php

namespace Tests\Feature\Exports;

use App\Models\BulkOperation;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Support\Permissions;
use Database\Factories\AccountFactory;
use Database\Factories\ContactFactory;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * §13.5, „Contacte: export CSV", peste mecanismul comun `ListExport`. Aceleași două căi ca la
 * conturi, verificate pe a doua resursă, ca extragerea să nu fi legat tăcut mecanismul de
 * `accounts`.
 */
class ContactExportTest extends TestCase
{
    private Tenant $marlin;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->marlin = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $this->owner = $this->makeMember($this->marlin, 'demo.owner@throughput.dev', Permissions::OWNER);

        $this->clearDatabaseTenantContext();
    }

    public function test_a_synchronous_export_contains_exactly_the_contacts_of_the_filtered_account(): void
    {
        $acmeId = TenantContext::run($this->marlin, function (): string {
            $acme = (new AccountFactory)->create(['name' => 'Acme Fasteners', 'created_by' => $this->owner->getKey()]);
            $other = (new AccountFactory)->create(['created_by' => $this->owner->getKey()]);

            (new ContactFactory)->count(3)->create(['account_id' => $acme->id, 'created_by' => $this->owner->getKey()]);
            (new ContactFactory)->count(2)->create(['account_id' => $other->id, 'created_by' => $this->owner->getKey()]);

            return $acme->id;
        });
        $this->clearDatabaseTenantContext();

        $response = $this->actingAs($this->owner)->get("/marlin/contacts/export?filter[account]={$acmeId}");

        $response->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('contacts-', $response->headers->get('Content-Disposition'));

        $lines = array_filter(explode("\n", trim($response->getContent())));
        // Antet + exact cele 3 contacte ale contului filtrat.
        $this->assertCount(4, $lines);
        $this->assertStringContainsString('Acme Fasteners', $response->getContent());
    }

    public function test_a_viewer_sees_the_export_action_and_can_use_it(): void
    {
        $viewer = $this->makeMember($this->marlin, 'demo.viewer@throughput.dev', Permissions::VIEWER);

        TenantContext::run($this->marlin, fn () => (new ContactFactory)->count(2)->create(['created_by' => $this->owner->getKey()]));
        $this->clearDatabaseTenantContext();

        $this->actingAs($viewer)->get('/marlin/contacts')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('can.export', true)
                ->where('can.create', false));

        $this->actingAs($viewer)->get('/marlin/contacts/export')->assertOk();
    }

    public function test_an_export_over_the_threshold_is_written_by_the_generic_queued_job(): void
    {
        Storage::fake('local');
        config(['throughput.limits.export_sync_max_rows' => 3]);

        TenantContext::run($this->marlin, fn () => (new ContactFactory)->count(5)->create(['created_by' => $this->owner->getKey()]));
        $this->clearDatabaseTenantContext();

        $this->actingAs($this->owner)->get('/marlin/contacts/export')->assertRedirect();

        $operation = TenantContext::run($this->marlin, fn () => BulkOperation::query()->where('resource_type', 'contacts')->firstOrFail());
        $this->assertSame(5, $operation->total_rows);

        $this->clearDatabaseTenantContext();
        $this->artisan('queue:work', ['--queue' => 'bulk', '--once' => true, '--no-interaction' => true]);

        $operation->refresh();
        $this->assertSame(BulkOperation::STATUS_COMPLETED, $operation->status);
        Storage::disk('local')->assertExists($operation->result_path);

        $lines = array_filter(explode("\n", trim(Storage::disk('local')->get($operation->result_path))));
        $this->assertCount(6, $lines);
    }
}
