<?php

namespace Tests\Feature\Bulk;

use App\Actions\Bulk\CancelDraftOrdersAction;
use App\Enums\OrderStatus;
use App\Jobs\Bulk\ProcessBulkChunkJob;
use App\Models\Account;
use App\Models\BulkOperation;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Support\Bulk\BulkChunkActions;
use App\Support\Bulk\Resources\OrderBulkResource;
use App\Support\Permissions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Comenzi — §13.5 (lotul E, valul „bulk"): reasignare owner (mecanismul generic, deja
 * acoperit exhaustiv de `ReassignOwnerTest` pe Accounts/Deals — aici doar RBAC-ul specific
 * Orders) și anulare în masă, DOAR `draft` (§11.3), care e mecanismul NOU al acestui pachet.
 */
class OrderBulkOperationsTest extends TestCase
{
    private Tenant $marlin;

    private User $owner;

    private User $manager;

    private User $agent;

    private User $viewer;

    private User $newOwner;

    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->marlin = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $this->owner = $this->makeMember($this->marlin, 'demo.owner@throughput.dev', Permissions::OWNER);
        $this->manager = $this->makeMember($this->marlin, 'demo.manager@throughput.dev', Permissions::MANAGER);
        $this->agent = $this->makeMember($this->marlin, 'demo.agent@throughput.dev', Permissions::AGENT);
        $this->viewer = $this->makeMember($this->marlin, 'demo.viewer@throughput.dev', Permissions::VIEWER);
        $this->newOwner = $this->makeMember($this->marlin, 'demo.new-owner@throughput.dev', Permissions::MANAGER);

        $this->account = TenantContext::run($this->marlin, function (): Account {
            $account = new Account(['name' => 'Northwind Industrial Supply LLC']);
            $account->created_by = $this->owner->getKey();
            $account->save();

            return $account;
        });

        $this->clearDatabaseTenantContext();
    }

    // ── Reasignare owner — RBAC specific Orders (mecanismul e cel deja testat pe Accounts/Deals) ──

    public function test_manager_can_reassign_order_owners(): void
    {
        TenantContext::run($this->marlin, fn () => $this->makeOrder($this->agent, OrderStatus::Draft));
        TenantContext::run($this->marlin, fn () => $this->makeOrder($this->agent, OrderStatus::Confirmed));
        $this->clearDatabaseTenantContext();

        $response = $this->actingAs($this->manager)->post(
            '/marlin/orders/bulk/reassign-owner',
            ['selectAllMatching' => true, 'owner_user_id' => $this->newOwner->getKey()],
        );

        $operation = $this->soleOperation('orders', 'reassign_owner');
        $response->assertRedirect('/marlin/bulk/'.$operation->getKey());
        $this->assertSame(2, $operation->total_rows);

        $this->drainBulkQueue();

        $owners = TenantContext::run($this->marlin, fn () => Order::query()->pluck('owner_user_id')->unique()->all());
        $this->assertSame([$this->newOwner->getKey()], $owners);
    }

    /** `orders.change_owner` n-o are Agentul în catalog — simetric cu Deals. */
    public function test_agent_cannot_reassign_order_owners_even_on_their_own_orders(): void
    {
        TenantContext::run($this->marlin, fn () => $this->makeOrder($this->agent, OrderStatus::Draft));
        $this->clearDatabaseTenantContext();

        $this->actingAs($this->agent)
            ->post('/marlin/orders/bulk/reassign-owner', ['selectAllMatching' => true, 'owner_user_id' => $this->newOwner->getKey()])
            ->assertForbidden();
    }

    public function test_viewer_cannot_reassign_order_owners(): void
    {
        TenantContext::run($this->marlin, fn () => $this->makeOrder($this->owner, OrderStatus::Draft));
        $this->clearDatabaseTenantContext();

        $this->actingAs($this->viewer)
            ->post('/marlin/orders/bulk/reassign-owner', ['selectAllMatching' => true, 'owner_user_id' => $this->newOwner->getKey()])
            ->assertForbidden();
    }

    // ── Anulare în masă, doar draft (§11.3, §13.5) ──────────────────────────────────

    public function test_owner_cancelling_a_mixed_selection_only_touches_the_drafts(): void
    {
        $draftIds = TenantContext::run($this->marlin, fn () => [
            $this->makeOrder($this->owner, OrderStatus::Draft)->getKey(),
            $this->makeOrder($this->owner, OrderStatus::Draft)->getKey(),
        ]);
        $confirmedId = TenantContext::run($this->marlin, fn () => $this->makeOrder($this->owner, OrderStatus::Confirmed)->getKey());
        $this->clearDatabaseTenantContext();

        $response = $this->actingAs($this->owner)->post('/marlin/orders/bulk/cancel-drafts', [
            'selectAllMatching' => true,
        ]);

        $operation = $this->soleOperation('orders', 'cancel_draft_orders');
        $response->assertRedirect('/marlin/bulk/'.$operation->getKey());
        // §13.5 — „Select all N" / total_rows capturat la dispatch trebuie să numere DOAR
        // draft-urile (2), nu tot filtrul (3) — defectul (g) din v1.24, reprodus altfel.
        $this->assertSame(2, $operation->total_rows);

        $this->drainBulkQueue();

        $statuses = TenantContext::run($this->marlin, fn () => Order::query()->whereIn('id', $draftIds)->pluck('status')->all());
        foreach ($statuses as $status) {
            $this->assertSame(OrderStatus::Cancelled, $status);
        }

        $confirmedStatus = TenantContext::run($this->marlin, fn () => Order::query()->find($confirmedId)->status);
        $this->assertSame(OrderStatus::Confirmed, $confirmedStatus, 'Comanda confirmată nu trebuia atinsă.');
    }

    /**
     * BR-BULK-02 — Agentul rămâne restrâns la subsetul propriu (draft-urile lui), chiar
     * dacă filtrul curent arată draft-uri ale altcuiva.
     */
    public function test_agent_cancelling_drafts_only_touches_their_own(): void
    {
        $ownDraftId = TenantContext::run($this->marlin, fn () => $this->makeOrder($this->agent, OrderStatus::Draft)->getKey());
        $othersDraftId = TenantContext::run($this->marlin, fn () => $this->makeOrder($this->manager, OrderStatus::Draft)->getKey());
        $this->clearDatabaseTenantContext();

        $response = $this->actingAs($this->agent)->post('/marlin/orders/bulk/cancel-drafts?filter[owner]=all', [
            'selectAllMatching' => true,
        ]);

        $operation = $this->soleOperation('orders', 'cancel_draft_orders');
        $response->assertRedirect('/marlin/bulk/'.$operation->getKey());
        $this->assertSame(1, $operation->total_rows, 'Filtrul „all" arată 2 draft-uri, dar Agentul deține doar 1.');

        $this->drainBulkQueue();

        $ownStatus = TenantContext::run($this->marlin, fn () => Order::query()->find($ownDraftId)->status);
        $this->assertSame(OrderStatus::Cancelled, $ownStatus);

        $othersStatus = TenantContext::run($this->marlin, fn () => Order::query()->find($othersDraftId)->status);
        $this->assertSame(OrderStatus::Draft, $othersStatus, 'Draft-ul altui membru nu trebuia atins.');
    }

    /**
     * Plafonul de rânduri (BR-BULK-02) trebuie verificat pe N-ul EFECTIV (draft-urile),
     * NU pe filtrul brut — un Agent cu 3 draft-uri proprii (sub plafonul de 5) nu trebuie
     * refuzat doar pentru că filtrul „all" arată 30 de comenzi de orice status.
     */
    public function test_the_row_cap_is_checked_against_the_draft_narrowed_count_not_the_raw_filter(): void
    {
        // Prag de confirmare = min(25% × plafon, 1.000) = 5 — sub el, 3 draft-uri nu cer
        // `confirmed`; peste el, cele 30 de comenzi confirmate din filtrul brut AR fi
        // cerut confirmare dacă pragul s-ar fi verificat greșit pe numărul brut (33).
        config(['throughput.limits.bulk_agent_row_cap' => 20]);

        TenantContext::run($this->marlin, function (): void {
            for ($i = 0; $i < 3; $i++) {
                $this->makeOrder($this->agent, OrderStatus::Draft);
            }

            for ($i = 0; $i < 30; $i++) {
                $this->makeOrder($this->agent, OrderStatus::Confirmed);
            }
        });
        $this->clearDatabaseTenantContext();

        $response = $this->actingAs($this->agent)->post('/marlin/orders/bulk/cancel-drafts', [
            'selectAllMatching' => true,
        ]);

        $operation = $this->soleOperation('orders', 'cancel_draft_orders');
        $response->assertRedirect('/marlin/bulk/'.$operation->getKey());
        $this->assertSame(3, $operation->total_rows);
    }

    /**
     * Inversul testului de mai sus — draft-urile efective DEPĂȘESC plafonul, deci refuzul
     * trebuie să se întâmple, deși filtrul brut ar arăta un total și mai mare.
     */
    public function test_the_row_cap_still_refuses_when_the_draft_narrowed_count_exceeds_it(): void
    {
        config(['throughput.limits.bulk_agent_row_cap' => 5]);

        TenantContext::run($this->marlin, function (): void {
            for ($i = 0; $i < 10; $i++) {
                $this->makeOrder($this->agent, OrderStatus::Draft);
            }
        });
        $this->clearDatabaseTenantContext();

        $this->actingAs($this->agent)
            ->post('/marlin/orders/bulk/cancel-drafts', ['selectAllMatching' => true])
            ->assertSessionHasErrors('selection');

        $this->assertSame(0, TenantContext::run($this->marlin, fn () => BulkOperation::query()->count()));
    }

    public function test_viewer_cannot_cancel_draft_orders(): void
    {
        TenantContext::run($this->marlin, fn () => $this->makeOrder($this->owner, OrderStatus::Draft));
        $this->clearDatabaseTenantContext();

        $this->actingAs($this->viewer)
            ->post('/marlin/orders/bulk/cancel-drafts', ['selectAllMatching' => true])
            ->assertForbidden();
    }

    /**
     * P3-003 (code review) — test lipsă: pe modul „ids" (checkbox de pagină), cu draft-uri
     * și comenzi non-draft amestecate în ACELEAȘI `ids` trimiși, doar draft-urile sunt
     * atinse — N-ul din `total_rows` (capturat la dispatch) e cel al draft-urilor, nu al
     * setului brut de `ids` trimis.
     */
    public function test_cancelling_an_explicit_id_selection_with_mixed_statuses_only_touches_the_drafts(): void
    {
        $draftIds = TenantContext::run($this->marlin, fn () => [
            $this->makeOrder($this->owner, OrderStatus::Draft)->getKey(),
            $this->makeOrder($this->owner, OrderStatus::Draft)->getKey(),
        ]);
        $confirmedId = TenantContext::run($this->marlin, fn () => $this->makeOrder($this->owner, OrderStatus::Confirmed)->getKey());
        $this->clearDatabaseTenantContext();

        $response = $this->actingAs($this->owner)->post('/marlin/orders/bulk/cancel-drafts', [
            'selectAllMatching' => false,
            'ids' => [...$draftIds, $confirmedId],
        ]);

        $operation = $this->soleOperation('orders', 'cancel_draft_orders');
        $response->assertRedirect('/marlin/bulk/'.$operation->getKey());
        $this->assertSame(2, $operation->total_rows, 'Doar cele 2 draft-uri din selecția explicită, nu cele 3 id-uri trimise.');

        $this->drainBulkQueue();

        $draftStatuses = TenantContext::run($this->marlin, fn () => Order::query()->whereIn('id', $draftIds)->pluck('status')->all());
        foreach ($draftStatuses as $status) {
            $this->assertSame(OrderStatus::Cancelled, $status);
        }

        $confirmedStatus = TenantContext::run($this->marlin, fn () => Order::query()->find($confirmedId)->status);
        $this->assertSame(OrderStatus::Confirmed, $confirmedStatus, 'Comanda confirmată, trimisă explicit în ids, nu trebuia atinsă.');
    }

    /** Idempotență de acțiune (rețeta din `App\Support\Bulk\BulkChunkAction`) — direct pe `apply()`. */
    public function test_running_the_same_cancel_chunk_twice_only_cancels_once(): void
    {
        $ids = TenantContext::run($this->marlin, fn () => [
            $this->makeOrder($this->owner, OrderStatus::Draft)->getKey(),
            $this->makeOrder($this->owner, OrderStatus::Draft)->getKey(),
        ]);

        TenantContext::run($this->marlin, function () use ($ids): void {
            $resource = new OrderBulkResource;
            $action = new CancelDraftOrdersAction;

            $firstRun = $action->apply($resource, $ids, []);
            $this->assertSame(2, $firstRun);

            $secondRun = $action->apply($resource, $ids, []);
            $this->assertSame(0, $secondRun, 'A doua rulare a aceluiași chunk nu trebuia să mai atingă niciun rând.');

            $statuses = Order::query()->whereIn('id', $ids)->pluck('status')->all();
            foreach ($statuses as $status) {
                $this->assertSame(OrderStatus::Cancelled, $status);
            }
        });
    }

    /**
     * Idempotență de CHUNK (code review „P2-001") — prin jobul real
     * (`ProcessBulkChunkJob`), nu doar prin `apply()`: reîncercarea ACELUIAȘI chunk
     * (același `bulk_operation_id` + index) sare peste `apply()` cu totul, verificat
     * distinct de idempotența „de rând" a acțiunii (un draft repus manual între cele două
     * rulări rămâne neatins — dacă `apply()` s-ar mai fi chemat, WHERE status='draft' l-ar
     * fi prins din nou).
     */
    public function test_running_the_same_cancel_chunk_twice_through_the_real_job_skips_the_second_apply(): void
    {
        $orderId = TenantContext::run($this->marlin, fn () => $this->makeOrder($this->owner, OrderStatus::Draft)->getKey());
        $this->clearDatabaseTenantContext();

        // `bulk_operation_chunks.bulk_operation_id` are FK către `bulk_operations`
        // (migrația `2026_09_14_160000`) — rând PĂRINTE real, nu un ULID arbitrar.
        $operationId = TenantContext::run($this->marlin, fn () => BulkOperation::query()->create([
            'user_id' => $this->owner->getKey(),
            'resource_type' => 'orders',
            'action' => BulkChunkActions::CANCEL_DRAFT_ORDERS,
            'filter_snapshot' => ['ids' => [$orderId], 'action_payload' => []],
            'total_rows' => 1,
            'status' => BulkOperation::STATUS_RUNNING,
        ])->getKey());

        ProcessBulkChunkJob::dispatch($this->marlin->getKey(), $operationId, 'orders', BulkChunkActions::CANCEL_DRAFT_ORDERS, [$orderId], [], 0)->onQueue('bulk');
        $this->drainBulkQueue();
        $this->assertSame(OrderStatus::Cancelled, TenantContext::run($this->marlin, fn () => Order::query()->find($orderId)->status));

        // Repunem manual pe draft, ca să distingem „chunk-ul a fost sărit" de „apply() a
        // rulat din nou și a găsit deja cancelled" — dacă `apply()` s-ar mai fi chemat,
        // `WHERE status = 'draft'` l-ar fi prins din nou și l-ar fi anulat.
        TenantContext::run($this->marlin, fn () => Order::query()->whereKey($orderId)->update(['status' => OrderStatus::Draft]));

        ProcessBulkChunkJob::dispatch($this->marlin->getKey(), $operationId, 'orders', BulkChunkActions::CANCEL_DRAFT_ORDERS, [$orderId], [], 0)->onQueue('bulk');
        $this->drainBulkQueue();

        $this->assertSame(
            OrderStatus::Draft,
            TenantContext::run($this->marlin, fn () => Order::query()->find($orderId)->status),
            'Chunk-ul redelivrat (același bulk_operation_id + index) nu trebuia să mai cheme apply().',
        );
    }

    private function makeOrder(User $owner, OrderStatus $status): Order
    {
        $order = new Order([
            'account_id' => $this->account->getKey(),
            'owner_user_id' => $owner->getKey(),
            'status' => $status,
            'currency' => 'USD',
        ]);
        $order->created_by = $this->owner->getKey();
        // `order_number` e nullabil pentru draft-uri (BR-ORD-02); confirmate/altele
        // primesc unul unic direct, ca `unique(tenant_id, order_number)` să nu pice.
        if ($status !== OrderStatus::Draft) {
            $order->order_number = 'TEST-'.Str::random(10);
        }
        $order->save();

        return $order;
    }

    private function soleOperation(string $resourceType, string $action): BulkOperation
    {
        return TenantContext::run(
            $this->marlin,
            fn () => BulkOperation::query()->where('resource_type', $resourceType)->where('action', $action)->firstOrFail(),
        );
    }

    private function drainBulkQueue(): void
    {
        $this->clearDatabaseTenantContext();

        $this->artisan('queue:work', [
            '--queue' => 'bulk',
            '--stop-when-empty' => true,
            '--no-interaction' => true,
        ]);
    }
}
