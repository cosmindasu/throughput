<?php

namespace Tests\Feature\Exports;

use App\Models\BulkOperation;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Support\Permissions;
use Database\Factories\AccountFactory;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * FR-GDPR-01 (specs.md §20.5) aplicat pe exportul de listă (§13.2): link valabil
 * `throughput.limits.export_retention_days` zile, apoi nedescărcabil — fără 500 — iar
 * pagina de status rămâne 200, cu starea de expirare.
 */
class ExportExpiryTest extends TestCase
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

    public function test_a_queued_export_gets_an_expiry_date_once_completed(): void
    {
        Storage::fake('local');
        config(['throughput.limits.export_sync_max_rows' => 5, 'throughput.limits.export_retention_days' => 7]);

        TenantContext::run($this->marlin, fn () => (new AccountFactory)->count(8)->create(['created_by' => $this->owner->getKey()]));
        $this->clearDatabaseTenantContext();

        $this->actingAs($this->owner)->get('/marlin/accounts/export')->assertRedirect();

        $operation = TenantContext::run($this->marlin, fn () => BulkOperation::query()->where('resource_type', 'accounts')->firstOrFail());
        $this->assertNull($operation->expires_at, 'Încă pending — data de expirare se pune abia la completare.');

        $this->clearDatabaseTenantContext();
        $this->artisan('queue:work', ['--queue' => 'bulk', '--once' => true, '--no-interaction' => true]);

        $operation = TenantContext::run($this->marlin, fn () => $operation->fresh());
        $this->assertSame(BulkOperation::STATUS_COMPLETED, $operation->status);
        $this->assertNotNull($operation->expires_at);
        $this->assertTrue(
            $operation->expires_at->between(now()->addDays(7)->subMinute(), now()->addDays(7)->addMinute()),
            'Retenția configurată (7 zile) trebuie reflectată exact în expires_at.'
        );
    }

    public function test_an_expired_export_cannot_be_downloaded_and_does_not_error(): void
    {
        Storage::fake('local');

        $operation = TenantContext::run($this->marlin, function () {
            $path = 'exports/'.$this->marlin->getKey().'/'.Str::ulid().'.csv';
            Storage::disk('local')->put($path, "Name\n");

            return BulkOperation::query()->create([
                'user_id' => $this->owner->getKey(),
                'resource_type' => 'accounts',
                'action' => 'export',
                'filter_snapshot' => ['filter' => [], 'sort' => 'name'],
                'total_rows' => 0,
                'status' => BulkOperation::STATUS_COMPLETED,
                'result_path' => $path,
                'expires_at' => now()->subMinute(),
            ]);
        });
        $this->clearDatabaseTenantContext();

        // Nici măcar autorul — link expirat, nu doar fișier lipsă — nu obține fișierul, dar
        // ruta răspunde curat (403 din Policy), nu o excepție de storage nemanipulată (500).
        $this->actingAs($this->owner)
            ->get("/marlin/exports/{$operation->id}/download")
            ->assertForbidden();
    }

    public function test_the_status_page_answers_200_with_the_expired_state(): void
    {
        Storage::fake('local');

        $operation = TenantContext::run($this->marlin, function () {
            $path = 'exports/'.$this->marlin->getKey().'/'.Str::ulid().'.csv';
            Storage::disk('local')->put($path, "Name\n");

            return BulkOperation::query()->create([
                'user_id' => $this->owner->getKey(),
                'resource_type' => 'accounts',
                'action' => 'export',
                'filter_snapshot' => ['filter' => [], 'sort' => 'name'],
                'total_rows' => 0,
                'status' => BulkOperation::STATUS_COMPLETED,
                'result_path' => $path,
                'expires_at' => now()->subDay(),
            ]);
        });
        $this->clearDatabaseTenantContext();

        $response = $this->actingAs($this->owner)->get("/marlin/exports/{$operation->id}");

        $response->assertOk();
        $export = $response->inertiaPage()['props']['export'];
        $this->assertTrue($export['isExpired']);
        $this->assertFalse($export['canDownload'], 'Policy-ul trebuie să refuze descărcarea o dată expirat.');
        $this->assertNotNull($export['expiresAt']);
    }

    public function test_the_status_page_shows_the_expiry_date_while_still_valid(): void
    {
        Storage::fake('local');

        $operation = TenantContext::run($this->marlin, function () {
            $path = 'exports/'.$this->marlin->getKey().'/'.Str::ulid().'.csv';
            Storage::disk('local')->put($path, "Name\n");

            return BulkOperation::query()->create([
                'user_id' => $this->owner->getKey(),
                'resource_type' => 'accounts',
                'action' => 'export',
                'filter_snapshot' => ['filter' => [], 'sort' => 'name'],
                'total_rows' => 0,
                'status' => BulkOperation::STATUS_COMPLETED,
                'result_path' => $path,
                'expires_at' => now()->addDays(6),
            ]);
        });
        $this->clearDatabaseTenantContext();

        $response = $this->actingAs($this->owner)->get("/marlin/exports/{$operation->id}");

        $response->assertOk();
        $export = $response->inertiaPage()['props']['export'];
        $this->assertFalse($export['isExpired']);
        $this->assertTrue($export['canDownload']);
    }
}
