<?php

namespace Tests\Feature\Imports;

use App\Models\Import;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Support\Permissions;
use Tests\TestCase;

/**
 * Pasul 2 — Mapare (§14.1 pct. 2). Salvarea mapării duce `status` la `mapped`; orice câmp
 * obligatoriu al resursei fără coloană mapată e refuzat cu mesaj clar.
 */
class ImportMappingTest extends TestCase
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

    public function test_saving_a_complete_mapping_moves_the_import_to_mapped(): void
    {
        $import = $this->makeImport();

        $response = $this->actingAs($this->owner)->post("/marlin/imports/{$import->getKey()}/mapping", [
            'mapping' => [
                'SKU' => 'sku',
                'Product Name' => 'product_name',
                'Price' => 'price',
                'Cost' => 'cost',
            ],
        ]);

        $response->assertRedirect("/marlin/imports/{$import->getKey()}");

        $fresh = TenantContext::run($this->marlin, fn () => $import->fresh());
        $this->assertSame(Import::STATUS_MAPPED, $fresh->status);
        $this->assertSame('sku', $fresh->column_mapping['SKU']);
    }

    public function test_a_mapping_missing_a_required_field_is_refused(): void
    {
        $import = $this->makeImport();

        $response = $this->actingAs($this->owner)->post("/marlin/imports/{$import->getKey()}/mapping", [
            // `sku` (obligatoriu pentru variants) rămâne nemapat.
            'mapping' => ['SKU' => null, 'Product Name' => 'product_name', 'Price' => 'price', 'Cost' => 'cost'],
        ]);

        $response->assertSessionHasErrors('mapping');

        $fresh = TenantContext::run($this->marlin, fn () => $import->fresh());
        $this->assertSame(Import::STATUS_UPLOADED, $fresh->status);
    }

    private function makeImport(): Import
    {
        return TenantContext::run($this->marlin, function (): Import {
            $import = new Import([
                'resource_type' => 'variants',
                'original_filename' => 'products.csv',
                'status' => Import::STATUS_UPLOADED,
            ]);
            $import->created_by = $this->owner->getKey();
            $import->save();

            return $import;
        });
    }
}
