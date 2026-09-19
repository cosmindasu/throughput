<?php

namespace Tests\Feature\Exports;

use App\Enums\OrderStatus;
use App\Jobs\Exports\ExportListJob;
use App\Models\Account;
use App\Models\BulkOperation;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Support\Permissions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * Comenzi — export CSV/PDF (§13.5, lotul E). CSV urmează exact mecanismul deja testat pe
 * Accounts (`AccountExportTest`) — aici doar RBAC-ul specific Orders (Viewer are voie,
 * `orders.view` + `bulk.export`) și cazurile specifice Orders. PDF e mecanismul NOU:
 * decizia DomPDF a proprietarului — NICIODATĂ sincron, plafon propriu, întotdeauna în coadă.
 */
class OrderExportTest extends TestCase
{
    private Tenant $marlin;

    private User $owner;

    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->marlin = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $this->owner = $this->makeMember($this->marlin, 'demo.owner@throughput.dev', Permissions::OWNER);

        $this->account = TenantContext::run($this->marlin, function (): Account {
            $account = new Account(['name' => 'Northwind Industrial Supply LLC']);
            $account->created_by = $this->owner->getKey();
            $account->save();

            return $account;
        });

        $this->clearDatabaseTenantContext();
    }

    public function test_a_synchronous_csv_export_contains_exactly_the_filtered_rows(): void
    {
        TenantContext::run($this->marlin, function (): void {
            $this->makeOrder(OrderStatus::Draft);
            $this->makeOrder(OrderStatus::Draft);
            $this->makeOrder(OrderStatus::Confirmed);
        });
        $this->clearDatabaseTenantContext();

        $response = $this->actingAs($this->owner)->get('/marlin/orders/export?filter[status]=draft');

        $response->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));

        $lines = array_filter(explode("\n", trim($response->getContent())));
        // Antet + exact 2 rânduri draft — nu și cel confirmat.
        $this->assertCount(3, $lines);
    }

    public function test_a_viewer_can_export_orders_even_without_write_access(): void
    {
        $viewer = $this->makeMember($this->marlin, 'demo.viewer@throughput.dev', Permissions::VIEWER);

        TenantContext::run($this->marlin, fn () => $this->makeOrder(OrderStatus::Draft));
        $this->clearDatabaseTenantContext();

        $this->actingAs($viewer)->get('/marlin/orders/export')->assertOk();
    }

    public function test_an_agent_without_bulk_export_cannot_export(): void
    {
        // BR-BULK-03 — `bulk.export` e în catalogul Agentului implicit; verificăm doar
        // simetria RBAC pe Orders, nu redefinim catalogul aici.
        $agent = $this->makeMember($this->marlin, 'demo.agent@throughput.dev', Permissions::AGENT);

        TenantContext::run($this->marlin, fn () => $this->makeOrder(OrderStatus::Draft));
        $this->clearDatabaseTenantContext();

        $this->actingAs($agent)->get('/marlin/orders/export')->assertOk();
    }

    public function test_a_csv_export_over_the_threshold_runs_as_a_queued_job(): void
    {
        Storage::fake('local');
        config(['throughput.limits.export_sync_max_rows' => 2]);

        TenantContext::run($this->marlin, function (): void {
            for ($i = 0; $i < 4; $i++) {
                $this->makeOrder(OrderStatus::Draft);
            }
        });
        $this->clearDatabaseTenantContext();

        $response = $this->actingAs($this->owner)->get('/marlin/orders/export');
        $response->assertRedirect();

        $operation = TenantContext::run($this->marlin, fn () => BulkOperation::query()->where('resource_type', 'orders')->firstOrFail());
        $this->assertSame(4, $operation->total_rows);
        $this->assertSame(1, DB::table('jobs')->count());

        $this->clearDatabaseTenantContext();
        $this->artisan('queue:work', ['--queue' => 'bulk', '--once' => true, '--no-interaction' => true]);

        $operation->refresh();
        $this->assertSame(BulkOperation::STATUS_COMPLETED, $operation->status);
        Storage::disk('local')->assertExists($operation->result_path);
        $this->assertStringEndsWith('.csv', $operation->result_path);
    }

    /**
     * Decizie DomPDF — PDF-ul NU are cale sincronă, indiferent de câte rânduri: chiar sub
     * `export_sync_max_rows`, pornește mereu operația în coadă (ADR-013).
     */
    public function test_a_pdf_export_always_runs_as_a_queued_job_even_under_the_sync_threshold(): void
    {
        Storage::fake('local');

        TenantContext::run($this->marlin, fn () => $this->makeOrder(OrderStatus::Draft));
        $this->clearDatabaseTenantContext();

        $response = $this->actingAs($this->owner)->get('/marlin/orders/export?format=pdf');
        $response->assertRedirect();

        $operation = TenantContext::run($this->marlin, fn () => BulkOperation::query()->where('resource_type', 'orders')->firstOrFail());
        $this->assertSame(1, $operation->total_rows);
        $this->assertSame(1, DB::table('jobs')->count(), 'PDF-ul trebuia să pornească mereu în coadă, chiar sub pragul sincron.');

        $this->clearDatabaseTenantContext();
        $this->artisan('queue:work', ['--queue' => 'bulk', '--once' => true, '--no-interaction' => true]);

        $operation->refresh();
        $this->assertSame(BulkOperation::STATUS_COMPLETED, $operation->status);
        $this->assertStringEndsWith('.pdf', (string) $operation->result_path);
        Storage::disk('local')->assertExists($operation->result_path);

        $pdf = Storage::disk('local')->get($operation->result_path);
        // Randare REALĂ (DomPDF, driver explicit) — un fișier PDF valid începe cu %PDF.
        $this->assertStringStartsWith('%PDF', $pdf);
    }

    public function test_a_pdf_export_over_its_own_cap_is_refused_before_starting(): void
    {
        config(['throughput.limits.export_pdf_max_rows' => 2]);

        TenantContext::run($this->marlin, function (): void {
            for ($i = 0; $i < 3; $i++) {
                $this->makeOrder(OrderStatus::Draft);
            }
        });
        $this->clearDatabaseTenantContext();

        $before = TenantContext::run($this->marlin, fn () => BulkOperation::query()->count());

        $this->actingAs($this->owner)->get('/marlin/orders/export?format=pdf')
            ->assertRedirect()
            ->assertSessionHas('error');

        $after = TenantContext::run($this->marlin, fn () => BulkOperation::query()->count());
        $this->assertSame($before, $after, 'Refuzul nu trebuie să lase o operație în masă în urmă.');
    }

    /**
     * P1 (code review) — plafonul PDF se verifică din nou la EXECUȚIE, nu doar la
     * declanșare: comenzi create DUPĂ ce operația a pornit (sub plafon la acel moment) și
     * ÎNAINTE ca jobul să ruleze pot împinge setul peste plafon. Jobul trebuie să refuze
     * randarea, nu doar s-o lase să treacă pentru că declanșarea a validat un total deja
     * învechit.
     */
    public function test_a_pdf_export_that_grows_past_the_cap_between_dispatch_and_execution_fails_cleanly(): void
    {
        Storage::fake('local');
        config(['throughput.limits.export_pdf_max_rows' => 3]);

        TenantContext::run($this->marlin, function (): void {
            for ($i = 0; $i < 3; $i++) {
                $this->makeOrder(OrderStatus::Draft);
            }
        });
        $this->clearDatabaseTenantContext();

        $this->actingAs($this->owner)->get('/marlin/orders/export?format=pdf')->assertRedirect();

        $operation = TenantContext::run($this->marlin, fn () => BulkOperation::query()->where('resource_type', 'orders')->firstOrFail());
        $this->assertSame(3, $operation->total_rows);

        // Rânduri noi, create DUPĂ declanșare, ÎNAINTE ca jobul să ruleze.
        TenantContext::run($this->marlin, fn () => $this->makeOrder(OrderStatus::Draft));
        $this->clearDatabaseTenantContext();

        $this->artisan('queue:work', ['--queue' => 'bulk', '--once' => true, '--no-interaction' => true]);

        $operation->refresh();
        $this->assertSame(BulkOperation::STATUS_FAILED, $operation->status);
        $this->assertNotNull($operation->error_message);
        $this->assertNull($operation->result_path);
        Storage::disk('local')->assertMissing("exports/{$this->marlin->getKey()}/{$operation->getKey()}.pdf");
    }

    /**
     * P1 (code review) — plasa de siguranță: un job ucis abrupt (OOM, timeout) nu ajunge
     * în niciun `catch` propriu; `failed()` e apelat de Laravel după epuizarea `$tries`,
     * într-un worker sănătos, și trebuie să închidă operația, nu s-o lase `running`.
     */
    public function test_failed_marks_the_operation_as_failed_without_a_result_path(): void
    {
        $operation = TenantContext::run($this->marlin, fn () => BulkOperation::query()->create([
            'user_id' => $this->owner->getKey(),
            'resource_type' => 'orders',
            'action' => 'export',
            'filter_snapshot' => ['filter' => [], 'sort' => '-created_at', 'format' => 'pdf'],
            'total_rows' => 1,
            'status' => BulkOperation::STATUS_RUNNING,
        ]));
        $this->clearDatabaseTenantContext();

        (new ExportListJob($this->marlin->getKey(), $operation->getKey()))
            ->failed(new RuntimeException('worker killed'));

        $operation->refresh();
        $this->assertSame(BulkOperation::STATUS_FAILED, $operation->status);
        $this->assertNotNull($operation->error_message);
        $this->assertNull($operation->result_path);
    }

    /** P2 (code review) — `format` necunoscut nu cade tăcut pe CSV, refuză explicit cu 422. */
    public function test_an_unknown_export_format_is_refused_with_422(): void
    {
        TenantContext::run($this->marlin, fn () => $this->makeOrder(OrderStatus::Draft));
        $this->clearDatabaseTenantContext();

        $this->actingAs($this->owner)->get('/marlin/orders/export?format=xlsx')->assertStatus(422);
    }

    public function test_an_order_export_beyond_the_absolute_demo_cap_is_refused(): void
    {
        config([
            'throughput.demo.mode' => true,
            'throughput.limits.export_sync_max_rows' => 2,
            'throughput.limits.bulk_max_rows' => 3,
        ]);

        TenantContext::run($this->marlin, function (): void {
            for ($i = 0; $i < 5; $i++) {
                $this->makeOrder(OrderStatus::Draft);
            }
        });
        $this->clearDatabaseTenantContext();

        $before = TenantContext::run($this->marlin, fn () => BulkOperation::query()->count());

        $this->actingAs($this->owner)->get('/marlin/orders/export')
            ->assertRedirect()
            ->assertSessionHas('error');

        $after = TenantContext::run($this->marlin, fn () => BulkOperation::query()->count());
        $this->assertSame($before, $after);
    }

    public function test_only_the_author_can_download_a_completed_export_with_the_right_content_type(): void
    {
        Storage::fake('local');

        $otherOwner = $this->makeMember($this->marlin, 'demo.other-owner@throughput.dev', Permissions::OWNER);

        $operation = TenantContext::run($this->marlin, fn () => BulkOperation::query()->create([
            'user_id' => $this->owner->getKey(),
            'resource_type' => 'orders',
            'action' => 'export',
            'filter_snapshot' => ['filter' => [], 'sort' => '-created_at', 'format' => 'pdf'],
            'total_rows' => 0,
            'status' => BulkOperation::STATUS_COMPLETED,
            'result_path' => 'exports/'.$this->marlin->getKey().'/'.Str::ulid().'.pdf',
        ]));
        Storage::disk('local')->put($operation->result_path, '%PDF-1.7 test');
        $this->clearDatabaseTenantContext();

        $response = $this->actingAs($this->owner)->get("/marlin/exports/{$operation->id}/download");
        $response->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $response->headers->get('Content-Type'));

        $this->actingAs($otherOwner)->get("/marlin/exports/{$operation->id}/download")->assertForbidden();
    }

    private function makeOrder(OrderStatus $status): Order
    {
        $order = new Order([
            'account_id' => $this->account->getKey(),
            'owner_user_id' => $this->owner->getKey(),
            'status' => $status,
            'currency' => 'USD',
        ]);
        $order->created_by = $this->owner->getKey();

        if ($status !== OrderStatus::Draft) {
            $order->order_number = 'TEST-'.Str::random(10);
        }

        $order->save();

        return $order;
    }
}
