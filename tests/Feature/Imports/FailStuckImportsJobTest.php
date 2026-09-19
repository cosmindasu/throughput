<?php

namespace Tests\Feature\Imports;

use App\Jobs\System\FailStuckImportsJob;
use App\Models\Import;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Support\Permissions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * P1, review general — plasa de sistem pentru importurile blocate/abandonate, pentru cazul
 * (rar, dar posibil) în care nimeni nu apasă „Cancel import" manual.
 */
class FailStuckImportsJobTest extends TestCase
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

    public function test_a_validating_import_stuck_past_the_threshold_is_failed(): void
    {
        config(['throughput.limits.import_stuck_minutes' => 15]);

        $import = $this->makeImportWithUpdatedAt(Import::STATUS_VALIDATING, now()->subMinutes(20));

        (new FailStuckImportsJob)->handle();

        $fresh = TenantContext::run($this->marlin, fn () => $import->fresh());
        $this->assertSame(Import::STATUS_FAILED, $fresh->status);
        $this->assertNotNull($fresh->completed_at);
    }

    public function test_a_validating_import_still_within_the_threshold_is_left_alone(): void
    {
        config(['throughput.limits.import_stuck_minutes' => 15]);

        $import = $this->makeImportWithUpdatedAt(Import::STATUS_VALIDATING, now()->subMinutes(5));

        (new FailStuckImportsJob)->handle();

        $fresh = TenantContext::run($this->marlin, fn () => $import->fresh());
        $this->assertSame(Import::STATUS_VALIDATING, $fresh->status);
    }

    public function test_an_uploaded_import_abandoned_past_the_threshold_is_failed(): void
    {
        config(['throughput.limits.import_abandoned_hours' => 24]);

        $import = $this->makeImportWithUpdatedAt(Import::STATUS_UPLOADED, now()->subHours(30));

        (new FailStuckImportsJob)->handle();

        $fresh = TenantContext::run($this->marlin, fn () => $import->fresh());
        $this->assertSame(Import::STATUS_FAILED, $fresh->status);
    }

    public function test_an_uploaded_import_still_within_the_abandoned_threshold_is_left_alone(): void
    {
        config(['throughput.limits.import_abandoned_hours' => 24]);

        $import = $this->makeImportWithUpdatedAt(Import::STATUS_UPLOADED, now()->subHours(2));

        (new FailStuckImportsJob)->handle();

        $fresh = TenantContext::run($this->marlin, fn () => $import->fresh());
        $this->assertSame(Import::STATUS_UPLOADED, $fresh->status);
    }

    private function makeImportWithUpdatedAt(string $status, Carbon $updatedAt): Import
    {
        return TenantContext::run($this->marlin, function () use ($status, $updatedAt): Import {
            $import = new Import([
                'resource_type' => 'products',
                'original_filename' => 'products.csv',
                'status' => $status,
            ]);
            $import->created_by = $this->owner->getKey();
            $import->save();

            // `Eloquent::update()` retușează `updated_at` la `now()` chiar și cu
            // `$model->timestamps = false` setat imediat înainte (verificat direct — nu
            // persista data din trecut) — `DB::table()` brut ocolește complet gestiunea de
            // timestamp-uri a Eloquent.
            DB::table('imports')->where('id', $import->getKey())->update(['updated_at' => $updatedAt]);

            return $import;
        });
    }
}
