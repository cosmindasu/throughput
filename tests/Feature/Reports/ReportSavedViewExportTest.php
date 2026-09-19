<?php

namespace Tests\Feature\Reports;

use App\Jobs\Reports\GenerateReportJob;
use App\Models\ReportDefinition;
use App\Models\ReportRun;
use App\Models\SavedView;
use App\Services\Tenancy\TenantContext;
use App\Support\Permissions;
use Database\Factories\AccountFactory;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Sursă `saved_view_export` (specs.md §16.1) — REFOLOSEȘTE mecanismul existent de export
 * (`ExportableResources`/`CsvExporter`), nu un al doilea mecanism. US-REP-01: un raport
 * dintr-o vedere salvată produce un fișier cu rândurile CURENTE ale vederii.
 */
class ReportSavedViewExportTest extends TestCase
{
    public function test_it_generates_a_csv_with_exactly_the_saved_views_current_rows(): void
    {
        Storage::fake('local');

        $marlin = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $owner = $this->makeMember($marlin, 'demo.owner@throughput.dev', Permissions::OWNER);

        [$report, $run] = TenantContext::run($marlin, function () use ($owner): array {
            (new AccountFactory)->count(2)->create(['created_by' => $owner->getKey(), 'status' => 'active']);
            (new AccountFactory)->count(3)->create(['created_by' => $owner->getKey(), 'status' => 'inactive']);

            $savedView = SavedView::forceCreate([
                'resource_type' => 'accounts',
                'name' => 'Active accounts',
                'filters' => ['status' => 'active'],
                'columns' => [],
                'sort' => 'name',
                'visibility' => SavedView::VISIBILITY_PRIVATE,
                'user_id' => $owner->getKey(),
            ]);

            $report = ReportDefinition::forceCreate([
                'report_type' => ReportDefinition::TYPE_SAVED_VIEW_EXPORT,
                'saved_view_id' => $savedView->getKey(),
                'name' => 'Active accounts export',
                'format' => 'csv',
                'schedule_frequency' => 'none',
                'recipients' => ['demo.owner@throughput.dev'],
                'is_active' => true,
                'created_by' => $owner->getKey(),
            ]);

            $run = ReportRun::query()->create([
                'report_definition_id' => $report->getKey(),
                'status' => ReportRun::STATUS_QUEUED,
                'triggered_by' => ReportRun::TRIGGERED_BY_MANUAL,
            ]);

            return [$report, $run];
        });

        (new GenerateReportJob($marlin->getKey(), $run->getKey()))->handle();

        $fresh = TenantContext::run($marlin, fn () => ReportRun::query()->find($run->getKey()));
        $this->clearDatabaseTenantContext();

        $this->assertSame(ReportRun::STATUS_SUCCESS, $fresh->status);
        $this->assertSame(2, $fresh->row_count, 'Only the 2 active accounts match the saved view filter.');

        $csv = Storage::disk('local')->get($fresh->file_path);
        $lines = array_filter(explode("\n", trim($csv)));
        $this->assertCount(3, $lines, 'Header + exactly 2 filtered rows.');
    }
}
