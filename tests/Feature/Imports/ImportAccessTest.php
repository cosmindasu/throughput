<?php

namespace Tests\Feature\Imports;

use App\Models\Import;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Support\Permissions;
use Tests\TestCase;

/**
 * §7.4, rândul „Import CSV": Owner CRUD, Manager CRUD, Agent „—", Viewer „—" — fără
 * îngustare pe proprietate. Plus izolarea de tenant (404 cross-tenant, prin global scope-ul
 * Eloquent, ca la orice altă resursă `BelongsToTenant`).
 */
class ImportAccessTest extends TestCase
{
    private Tenant $marlin;

    private Tenant $cascade;

    private User $owner;

    private User $manager;

    private User $agent;

    private User $viewer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->marlin = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $this->cascade = $this->makeTenant('cascade', 'Cascade Hydraulic Components');

        $this->owner = $this->makeMember($this->marlin, 'demo.owner@throughput.dev', Permissions::OWNER);
        $this->manager = $this->makeMember($this->marlin, 'demo.manager@throughput.dev', Permissions::MANAGER);
        $this->agent = $this->makeMember($this->marlin, 'demo.agent@throughput.dev', Permissions::AGENT);
        $this->viewer = $this->makeMember($this->marlin, 'demo.viewer@throughput.dev', Permissions::VIEWER);

        $this->clearDatabaseTenantContext();
    }

    public function test_owner_can_view_the_imports_list(): void
    {
        $this->actingAs($this->owner)->get('/marlin/imports')->assertOk();
    }

    public function test_manager_can_view_the_imports_list(): void
    {
        $this->actingAs($this->manager)->get('/marlin/imports')->assertOk();
    }

    public function test_agent_cannot_view_the_imports_list(): void
    {
        $this->actingAs($this->agent)->get('/marlin/imports')->assertForbidden();
    }

    public function test_viewer_cannot_view_the_imports_list(): void
    {
        $this->actingAs($this->viewer)->get('/marlin/imports')->assertForbidden();
    }

    public function test_agent_cannot_view_the_create_page(): void
    {
        $this->actingAs($this->agent)->get('/marlin/imports/create')->assertForbidden();
    }

    public function test_viewer_cannot_view_the_create_page(): void
    {
        $this->actingAs($this->viewer)->get('/marlin/imports/create')->assertForbidden();
    }

    public function test_agent_cannot_upload_a_file(): void
    {
        $this->actingAs($this->agent)
            ->post('/marlin/imports', ['resource_type' => 'products', 'file' => 'irrelevant'])
            ->assertForbidden();
    }

    public function test_viewer_cannot_upload_a_file(): void
    {
        $this->actingAs($this->viewer)
            ->post('/marlin/imports', ['resource_type' => 'products', 'file' => 'irrelevant'])
            ->assertForbidden();
    }

    public function test_agent_cannot_view_an_existing_import(): void
    {
        $import = $this->makeImport($this->marlin, $this->owner);

        $this->actingAs($this->agent)->get("/marlin/imports/{$import->getKey()}")->assertForbidden();
    }

    public function test_viewer_cannot_view_an_existing_import(): void
    {
        $import = $this->makeImport($this->marlin, $this->owner);

        $this->actingAs($this->viewer)->get("/marlin/imports/{$import->getKey()}")->assertForbidden();
    }

    public function test_agent_cannot_trigger_dry_run_or_commit(): void
    {
        $import = $this->makeImport($this->marlin, $this->owner, Import::STATUS_MAPPED);

        $this->actingAs($this->agent)->post("/marlin/imports/{$import->getKey()}/dry-run")->assertForbidden();
        $this->actingAs($this->agent)->post("/marlin/imports/{$import->getKey()}/commit")->assertForbidden();
    }

    public function test_an_import_from_another_tenant_is_not_found(): void
    {
        $cascadeOwner = $this->makeMember($this->cascade, 'demo.owner@cascade.dev', Permissions::OWNER);
        $import = $this->makeImport($this->cascade, $cascadeOwner);

        $this->actingAs($this->owner)->get("/marlin/imports/{$import->getKey()}")->assertNotFound();
    }

    private function makeImport(Tenant $tenant, User $user, string $status = Import::STATUS_UPLOADED): Import
    {
        return TenantContext::run($tenant, function () use ($user, $status): Import {
            $import = new Import([
                'resource_type' => 'products',
                'original_filename' => 'products.csv',
                'status' => $status,
            ]);
            $import->created_by = $user->getKey();
            $import->save();

            return $import;
        });
    }
}
