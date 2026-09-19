<?php

namespace Tests\Feature\Reports;

use App\Models\ReportDefinition;
use App\Models\ReportRun;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Support\Permissions;
use Tests\TestCase;

/**
 * Izolare de tenant (prioritate maximă, `.ai/rules/tenancy.md`) — un id valid dintr-un ALT
 * tenant trebuie să dea 404, niciodată 403 (403 ar confirma că rândul există).
 */
class ReportTenancyTest extends TestCase
{
    public function test_a_report_from_another_tenant_is_a_404_not_a_403(): void
    {
        $marlin = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $cascade = $this->makeTenant('cascade', 'Cascade Hydraulic Components');

        $marlinOwner = $this->makeMember($marlin, 'demo.owner@throughput.dev', Permissions::OWNER);
        $this->makeMember($cascade, 'demo.owner@cascade.dev', Permissions::OWNER);

        [$cascadeReport, $cascadeRun] = TenantContext::run($cascade, function () {
            $report = ReportDefinition::forceCreate([
                'report_type' => ReportDefinition::TYPE_DEAL_VELOCITY,
                'name' => 'Cascade report',
                'format' => 'csv',
                'schedule_frequency' => 'none',
                'recipients' => ['demo.owner@cascade.dev'],
                'is_active' => true,
                'created_by' => User::query()->where('email', 'demo.owner@cascade.dev')->firstOrFail()->getKey(),
            ]);

            $run = ReportRun::query()->create([
                'report_definition_id' => $report->getKey(),
                'status' => ReportRun::STATUS_SUCCESS,
                'triggered_by' => ReportRun::TRIGGERED_BY_MANUAL,
                'file_path' => 'reports/'.$report->tenant_id.'/fake.csv',
                'row_count' => 1,
            ]);

            return [$report, $run];
        });
        $this->clearDatabaseTenantContext();

        $this->actingAs($marlinOwner)->get("/marlin/reports/{$cascadeReport->id}")->assertNotFound();
        $this->actingAs($marlinOwner)->get("/marlin/reports/{$cascadeReport->id}/edit")->assertNotFound();
        $this->actingAs($marlinOwner)->put("/marlin/reports/{$cascadeReport->id}", [])->assertNotFound();
        $this->actingAs($marlinOwner)->delete("/marlin/reports/{$cascadeReport->id}")->assertNotFound();

        // Fix P3 (review) — „run"/„download" cross-tenant nu erau acoperite explicit.
        $this->actingAs($marlinOwner)->post("/marlin/reports/{$cascadeReport->id}/run")->assertNotFound();
        $this->actingAs($marlinOwner)
            ->get("/marlin/reports/{$cascadeReport->id}/runs/{$cascadeRun->id}/download")
            ->assertNotFound();
    }
}
