<?php

namespace Tests\Feature\Gdpr;

use App\Models\Account;
use App\Models\DataExportRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Support\Permissions;
use Database\Factories\AccountFactory;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * FR-GDPR-01/02, BR-GDPR-01/02, US-GDPR-01 (specs.md §20.5) — ecranul „Export data":
 * cine îl vede, cine poate declanșa, ce se întâmplă în cererea HTTP și ce NU se întâmplă.
 */
class DataExportRequestTest extends TestCase
{
    private Tenant $marlin;

    private User $owner;

    private User $manager;

    private User $agent;

    private User $viewer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->marlin = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $this->owner = $this->makeMember($this->marlin, 'demo.owner@throughput.dev', Permissions::OWNER);
        $this->manager = $this->makeMember($this->marlin, 'demo.manager@throughput.dev', Permissions::MANAGER);
        $this->agent = $this->makeMember($this->marlin, 'demo.agent@throughput.dev', Permissions::AGENT);
        $this->viewer = $this->makeMember($this->marlin, 'demo.viewer@throughput.dev', Permissions::VIEWER);

        $this->clearDatabaseTenantContext();
    }

    public function test_owner_sees_the_screen_with_the_request_button(): void
    {
        $this->actingAs($this->owner)
            ->get('/marlin/settings/data-export')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Settings/DataExport/Index')
                ->where('can.create', true)
                ->where('retentionDays', 7)
                ->has('requests', 0)
            );
    }

    /**
     * BR-GDPR-01 — „Manager poate vedea istoricul, dar nu poate declanșa unul nou".
     * Butonul lipsește (FR-RBAC-01), nu e randat dezactivat.
     */
    public function test_manager_sees_the_history_without_the_request_button(): void
    {
        $this->makeRequest($this->owner);

        $this->actingAs($this->manager)
            ->get('/marlin/settings/data-export')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Settings/DataExport/Index')
                ->where('can.create', false)
                ->has('requests', 1)
                ->where('requests.0.requestedBy.name', $this->owner->name)
            );
    }

    public function test_agent_and_viewer_cannot_open_the_screen_at_all(): void
    {
        $this->actingAs($this->agent)->get('/marlin/settings/data-export')->assertForbidden();
        $this->actingAs($this->viewer)->get('/marlin/settings/data-export')->assertForbidden();
    }

    public function test_owner_can_queue_an_export_which_is_visible_immediately_as_queued(): void
    {
        $this->actingAs($this->owner)
            ->post('/marlin/settings/data-export')
            ->assertRedirect();

        $export = $this->soleRequest();

        $this->assertSame(DataExportRequest::STATUS_QUEUED, $export->status);
        $this->assertSame($this->owner->getKey(), $export->requested_by);
        $this->assertNotNull($export->requested_at);
        $this->assertNull($export->file_path);

        // ADR-013 — în cererea HTTP nu se face nicio muncă de export: doar rândul și jobul.
        $this->assertSame(1, DB::table('jobs')->where('queue', 'bulk')->count());
    }

    public function test_manager_agent_and_viewer_cannot_queue_an_export(): void
    {
        foreach ([$this->manager, $this->agent, $this->viewer] as $user) {
            $this->actingAs($user)->post('/marlin/settings/data-export')->assertForbidden();
        }

        $this->assertSame(0, $this->requestCount());
        $this->assertSame(0, DB::table('jobs')->count());
    }

    /**
     * §22.5 / ADR-017 — un singur worker de coadă, cu `memory_limit=256M`: două exporturi
     * concurente ar dubla vârful de memorie fără să producă nimic în plus.
     */
    public function test_a_second_export_is_refused_while_one_is_still_running(): void
    {
        $this->actingAs($this->owner)->post('/marlin/settings/data-export')->assertRedirect();

        $this->actingAs($this->owner)
            ->post('/marlin/settings/data-export')
            ->assertSessionHasErrors('export');

        $this->assertSame(1, $this->requestCount());
        $this->assertSame(1, DB::table('jobs')->where('queue', 'bulk')->count());
    }

    public function test_a_new_export_is_allowed_once_the_previous_one_finished(): void
    {
        $this->makeRequest($this->owner, DataExportRequest::STATUS_COMPLETED);

        $this->actingAs($this->owner)->post('/marlin/settings/data-export')->assertRedirect();

        $this->assertSame(2, $this->requestCount());
    }

    /**
     * BR-GDPR-02 — „declanșarea unui export nu afectează în niciun fel datele sursă".
     */
    public function test_requesting_an_export_does_not_touch_the_source_data(): void
    {
        TenantContext::run($this->marlin, function (): void {
            (new AccountFactory)->count(3)->create([
                'created_by' => $this->owner->getKey(),
                'owner_user_id' => $this->owner->getKey(),
                'status' => Account::STATUS_ACTIVE,
            ]);
        });
        $this->clearDatabaseTenantContext();

        $before = TenantContext::run($this->marlin, fn () => Account::query()->pluck('updated_at', 'id')->all());

        $this->actingAs($this->owner)->post('/marlin/settings/data-export')->assertRedirect();

        $after = TenantContext::run($this->marlin, fn () => Account::query()->pluck('updated_at', 'id')->all());

        $this->assertSame(3, count($after));
        $this->assertEquals($before, $after);
    }

    /**
     * Izolarea istoricului: `data_export_requests` are RLS (migrația din Faza 1), deci un
     * Owner dintr-un alt workspace nu vede cererile acestuia.
     */
    public function test_the_history_is_scoped_to_the_current_workspace(): void
    {
        $cascade = $this->makeTenant('cascade', 'Cascade Hydraulic Components');
        $cascadeOwner = $this->makeMember($cascade, 'demo.cascade-owner@throughput.dev', Permissions::OWNER);
        $this->clearDatabaseTenantContext();

        $this->makeRequest($this->owner);

        $this->actingAs($cascadeOwner)
            ->get('/cascade/settings/data-export')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('requests', 0));
    }

    private function makeRequest(User $user, string $status = DataExportRequest::STATUS_QUEUED): DataExportRequest
    {
        $export = TenantContext::run($this->marlin, function () use ($user, $status): DataExportRequest {
            $export = new DataExportRequest([
                'status' => $status,
                'requested_at' => now(),
                'completed_at' => $status === DataExportRequest::STATUS_COMPLETED ? now() : null,
            ]);
            $export->requested_by = $user->getKey();
            $export->save();

            return $export;
        });

        $this->clearDatabaseTenantContext();

        return $export;
    }

    private function soleRequest(): DataExportRequest
    {
        $export = TenantContext::run($this->marlin, fn () => DataExportRequest::query()->sole());
        $this->clearDatabaseTenantContext();

        return $export;
    }

    private function requestCount(): int
    {
        $count = TenantContext::run($this->marlin, fn (): int => DataExportRequest::query()->count());
        $this->clearDatabaseTenantContext();

        return $count;
    }
}
