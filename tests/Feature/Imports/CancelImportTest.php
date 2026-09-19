<?php

namespace Tests\Feature\Imports;

use App\Models\Import;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Support\Permissions;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * P1, review general — calea PRINCIPALĂ de recuperare dintr-un import blocat/abandonat
 * (`CancelImportAction`): orice status NE-terminal poate fi anulat, server-side, iar odată
 * anulat, un import nou poate porni (§22.5).
 */
class CancelImportTest extends TestCase
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

    public function test_owner_can_cancel_an_uploaded_import(): void
    {
        $import = $this->makeImport(Import::STATUS_UPLOADED);

        $this->actingAs($this->owner)->post("/marlin/imports/{$import->getKey()}/cancel")->assertRedirect();

        $fresh = TenantContext::run($this->marlin, fn () => $import->fresh());
        $this->assertSame(Import::STATUS_FAILED, $fresh->status);
        $this->assertNotNull($fresh->completed_at);
    }

    public function test_cancelling_frees_up_the_tenant_for_a_new_import(): void
    {
        $import = $this->makeImport(Import::STATUS_MAPPED);

        $this->actingAs($this->owner)->post("/marlin/imports/{$import->getKey()}/cancel");

        $file = UploadedFile::fake()->createWithContent('second.csv', "Product Name\nWidget\n");

        $this->actingAs($this->owner)
            ->post('/marlin/imports', ['resource_type' => 'products', 'file' => $file])
            ->assertSessionDoesntHaveErrors();

        $this->assertSame(2, TenantContext::run($this->marlin, fn () => Import::query()->count()));
    }

    public function test_a_completed_import_cannot_be_cancelled(): void
    {
        $import = $this->makeImport(Import::STATUS_COMPLETED);

        $this->actingAs($this->owner)
            ->post("/marlin/imports/{$import->getKey()}/cancel")
            ->assertSessionHasErrors('status');

        $fresh = TenantContext::run($this->marlin, fn () => $import->fresh());
        $this->assertSame(Import::STATUS_COMPLETED, $fresh->status);
    }

    public function test_agent_cannot_cancel_an_import(): void
    {
        $agent = $this->makeMember($this->marlin, 'demo.agent@throughput.dev', Permissions::AGENT);
        $import = $this->makeImport(Import::STATUS_UPLOADED);

        $this->actingAs($agent)->post("/marlin/imports/{$import->getKey()}/cancel")->assertForbidden();
    }

    private function makeImport(string $status): Import
    {
        return TenantContext::run($this->marlin, function () use ($status): Import {
            $import = new Import([
                'resource_type' => 'products',
                'original_filename' => 'products.csv',
                'status' => $status,
            ]);
            $import->created_by = $this->owner->getKey();
            $import->save();

            return $import;
        });
    }
}
