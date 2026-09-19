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
 * Pasul 1 — Upload (§14.1, FR-IMP-02): extensie, dimensiune, număr de rânduri — toate
 * verificate ÎN CERERE, cu mesaj clar, nu o excepție brută mai târziu în job. Plus §22.5
 * (un singur import activ per tenant).
 */
class ImportUploadTest extends TestCase
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

    public function test_a_valid_csv_upload_creates_an_uploaded_import(): void
    {
        $file = UploadedFile::fake()->createWithContent('products.csv', "SKU,Product Name,Price,Cost\nA-1,Widget,9.99,4.00\n");

        $response = $this->actingAs($this->owner)->post('/marlin/imports', [
            'resource_type' => 'variants',
            'file' => $file,
        ]);

        $import = TenantContext::run($this->marlin, fn () => Import::query()->firstOrFail());
        $response->assertRedirect("/marlin/imports/{$import->getKey()}");
        $this->assertSame(Import::STATUS_UPLOADED, $import->status);
        $this->assertSame('variants', $import->resource_type);
        $this->assertSame('products.csv', $import->original_filename);
    }

    public function test_an_unsupported_extension_is_rejected(): void
    {
        $file = UploadedFile::fake()->create('products.pdf', 10, 'application/pdf');

        $this->actingAs($this->owner)
            ->post('/marlin/imports', ['resource_type' => 'variants', 'file' => $file])
            ->assertSessionHasErrors('file');

        $this->assertSame(0, TenantContext::run($this->marlin, fn () => Import::query()->count()));
    }

    public function test_a_file_over_the_size_limit_is_rejected_with_a_clear_message(): void
    {
        config(['throughput.limits.import_max_file_mb' => 1]);

        $file = UploadedFile::fake()->create('products.csv', 2000, 'text/csv');

        $this->actingAs($this->owner)
            ->post('/marlin/imports', ['resource_type' => 'variants', 'file' => $file])
            ->assertSessionHasErrors('file');

        $this->assertSame(0, TenantContext::run($this->marlin, fn () => Import::query()->count()));
    }

    public function test_a_file_over_the_row_limit_is_rejected_with_the_row_count_in_the_message(): void
    {
        config(['throughput.limits.import_max_rows' => 3]);

        $content = "SKU,Product Name,Price,Cost\n";

        for ($i = 1; $i <= 5; $i++) {
            $content .= "SKU-{$i},Widget {$i},9.99,4.00\n";
        }

        $file = UploadedFile::fake()->createWithContent('products.csv', $content);

        $response = $this->actingAs($this->owner)->post('/marlin/imports', ['resource_type' => 'variants', 'file' => $file]);

        $response->assertSessionHasErrors('file');
        $this->assertStringContainsString('5 rows', session('errors')->first('file'));
        $this->assertSame(0, TenantContext::run($this->marlin, fn () => Import::query()->count()));
    }

    public function test_a_second_concurrent_import_is_refused_with_a_clear_message(): void
    {
        TenantContext::run($this->marlin, function (): void {
            $import = new Import(['resource_type' => 'products', 'original_filename' => 'first.csv', 'status' => Import::STATUS_MAPPED]);
            $import->created_by = $this->owner->getKey();
            $import->save();
        });
        $this->clearDatabaseTenantContext();

        $file = UploadedFile::fake()->createWithContent('second.csv', "Product Name\nWidget\n");

        $response = $this->actingAs($this->owner)->post('/marlin/imports', ['resource_type' => 'products', 'file' => $file]);

        $response->assertSessionHasErrors('file');
        $this->assertStringContainsString('one active import', session('errors')->first('file'));
        $this->assertSame(1, TenantContext::run($this->marlin, fn () => Import::query()->count()));
    }

    public function test_a_second_import_can_start_once_the_first_is_terminal(): void
    {
        TenantContext::run($this->marlin, function (): void {
            $import = new Import(['resource_type' => 'products', 'original_filename' => 'first.csv', 'status' => Import::STATUS_COMPLETED]);
            $import->created_by = $this->owner->getKey();
            $import->save();
        });
        $this->clearDatabaseTenantContext();

        $file = UploadedFile::fake()->createWithContent('second.csv', "Product Name\nWidget\n");

        $this->actingAs($this->owner)
            ->post('/marlin/imports', ['resource_type' => 'products', 'file' => $file])
            ->assertSessionDoesntHaveErrors('file');

        $this->assertSame(2, TenantContext::run($this->marlin, fn () => Import::query()->count()));
    }
}
