<?php

namespace Tests\Feature\Members;

use App\Models\Account;
use App\Models\BulkOperation;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Support\Permissions;
use Database\Factories\AccountFactory;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * BR-BULK-04 — progresul AGREGAT al unui `group_id` (`Bulk/Groups/Show`). Nu repetă
 * `ReassignOwnerTest` (mecanismul de bază e deja acoperit acolo); verifică doar ce e NOU
 * aici: agregarea pe mai multe rânduri `bulk_operations` și autorizarea per grup.
 */
class BulkOperationGroupTest extends TestCase
{
    private Tenant $marlin;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        config(['throughput.demo.mode' => false]);

        $this->marlin = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $this->owner = $this->makeMember($this->marlin, 'owner@throughput.dev', Permissions::OWNER);
    }

    public function test_the_group_page_reports_aggregated_progress_across_its_operations(): void
    {
        $jane = $this->makeMember($this->marlin, 'jane@throughput.dev', Permissions::AGENT);
        $newOwner = $this->makeMember($this->marlin, 'newowner@throughput.dev', Permissions::MANAGER);

        TenantContext::run($this->marlin, fn () => (new AccountFactory)->count(4)->create([
            'created_by' => $this->owner->getKey(),
            'owner_user_id' => $jane->getKey(),
            'status' => Account::STATUS_ACTIVE,
        ]));
        $this->clearDatabaseTenantContext();

        $this->actingAs($this->owner)->post(
            "/marlin/settings/members/{$this->membershipOf($jane)->getKey()}/deactivate",
            ['reassign' => true, 'new_owner_user_id' => $newOwner->getKey()],
        );

        $groupId = TenantContext::run($this->marlin, fn () => BulkOperation::query()->value('group_id'));

        $this->actingAs($this->owner)
            ->get("/marlin/bulk/groups/{$groupId}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Bulk/Groups/Show')
                ->where('group.groupId', $groupId)
                ->has('group.operations', 3)
            );

        $this->drainBulkQueue();

        $this->actingAs($this->owner)
            ->get("/marlin/bulk/groups/{$groupId}")
            ->assertInertia(fn (Assert $page) => $page->where('group.status', 'completed'));
    }

    public function test_only_the_actor_who_started_the_group_can_open_its_progress_page(): void
    {
        $jane = $this->makeMember($this->marlin, 'jane@throughput.dev', Permissions::AGENT);
        $newOwner = $this->makeMember($this->marlin, 'newowner@throughput.dev', Permissions::MANAGER);
        $secondOwner = $this->makeMember($this->marlin, 'second.owner@throughput.dev', Permissions::OWNER);

        $this->actingAs($this->owner)->post(
            "/marlin/settings/members/{$this->membershipOf($jane)->getKey()}/deactivate",
            ['reassign' => true, 'new_owner_user_id' => $newOwner->getKey()],
        );

        $groupId = TenantContext::run($this->marlin, fn () => BulkOperation::query()->value('group_id'));

        $this->actingAs($secondOwner)->get("/marlin/bulk/groups/{$groupId}")->assertForbidden();
    }

    public function test_an_unknown_group_id_is_a_404(): void
    {
        $this->actingAs($this->owner)->get('/marlin/bulk/groups/01ARZ3NDEKTSV4RRFFQ69G5FAV')->assertNotFound();
    }

    /**
     * P2-003 (review general) — drumul fericit al anulării unui grup, INCLUSIV operații
     * încă `pending` fără `batch_id` (planificatorul n-a apucat să ruleze) — exact
     * fereastra pe care `BulkOperationGroupController::cancelOne()` o tratează cu
     * `UPDATE`-ul atomic condiționat pe stare, nu doar cu `$batch->cancel()`.
     */
    public function test_the_group_owner_can_cancel_the_whole_group(): void
    {
        $jane = $this->makeMember($this->marlin, 'jane@throughput.dev', Permissions::AGENT);
        $newOwner = $this->makeMember($this->marlin, 'newowner@throughput.dev', Permissions::MANAGER);

        TenantContext::run($this->marlin, fn () => (new AccountFactory)->count(2)->create([
            'created_by' => $this->owner->getKey(),
            'owner_user_id' => $jane->getKey(),
            'status' => Account::STATUS_ACTIVE,
        ]));
        $this->clearDatabaseTenantContext();

        $this->actingAs($this->owner)->post(
            "/marlin/settings/members/{$this->membershipOf($jane)->getKey()}/deactivate",
            ['reassign' => true, 'new_owner_user_id' => $newOwner->getKey()],
        );

        $groupId = TenantContext::run($this->marlin, fn () => BulkOperation::query()->value('group_id'));

        // Toate trei rândurile sunt încă `pending` — planificatorul rulează pe coada
        // `bulk`, nedrenată încă în acest test.
        $statusesBeforeCancel = TenantContext::run($this->marlin, fn () => BulkOperation::query()->pluck('status')->unique()->all());
        $this->assertSame([BulkOperation::STATUS_PENDING], $statusesBeforeCancel);

        $this->actingAs($this->owner)
            ->post("/marlin/bulk/groups/{$groupId}/cancel")
            ->assertRedirect();

        $this->drainBulkQueue();

        $statusesAfterCancel = TenantContext::run($this->marlin, fn () => BulkOperation::query()->pluck('status')->unique()->all());
        $this->assertSame([BulkOperation::STATUS_CANCELLED], $statusesAfterCancel);

        $this->actingAs($this->owner)
            ->get("/marlin/bulk/groups/{$groupId}")
            ->assertInertia(fn (Assert $page) => $page->where('group.status', 'cancelled')->where('group.canCancel', false));
    }

    public function test_only_the_actor_who_started_the_group_can_cancel_it(): void
    {
        $jane = $this->makeMember($this->marlin, 'jane@throughput.dev', Permissions::AGENT);
        $newOwner = $this->makeMember($this->marlin, 'newowner@throughput.dev', Permissions::MANAGER);
        $secondOwner = $this->makeMember($this->marlin, 'second.owner@throughput.dev', Permissions::OWNER);

        $this->actingAs($this->owner)->post(
            "/marlin/settings/members/{$this->membershipOf($jane)->getKey()}/deactivate",
            ['reassign' => true, 'new_owner_user_id' => $newOwner->getKey()],
        );

        $groupId = TenantContext::run($this->marlin, fn () => BulkOperation::query()->value('group_id'));

        $this->actingAs($secondOwner)->post("/marlin/bulk/groups/{$groupId}/cancel")->assertForbidden();

        $statuses = TenantContext::run($this->marlin, fn () => BulkOperation::query()->pluck('status')->unique()->all());
        $this->assertSame([BulkOperation::STATUS_PENDING], $statuses, 'Refuzul nu trebuie să atingă nimic.');
    }

    public function test_a_group_from_another_tenant_cannot_be_cancelled(): void
    {
        $cascade = $this->makeTenant('cascade', 'Cascade Hydraulic Components');
        $cascadeOwner = $this->makeMember($cascade, 'cascade.owner@throughput.dev', Permissions::OWNER);
        $jane = $this->makeMember($cascade, 'cascade.jane@throughput.dev', Permissions::AGENT);
        $newOwner = $this->makeMember($cascade, 'cascade.newowner@throughput.dev', Permissions::MANAGER);

        $this->actingAs($cascadeOwner)->post(
            "/cascade/settings/members/{$this->membershipOf($jane, $cascade)->getKey()}/deactivate",
            ['reassign' => true, 'new_owner_user_id' => $newOwner->getKey()],
        );

        $groupId = TenantContext::run($cascade, fn () => BulkOperation::query()->value('group_id'));

        // Marlin — RLS ascunde rândurile lui Cascade, deci grupul nici nu există aici.
        $this->actingAs($this->owner)->get("/marlin/bulk/groups/{$groupId}")->assertNotFound();
        $this->actingAs($this->owner)->post("/marlin/bulk/groups/{$groupId}/cancel")->assertNotFound();
    }

    private function membershipOf(User $user, ?Tenant $tenant = null): Membership
    {
        return TenantContext::run($tenant ?? $this->marlin, fn () => Membership::query()->where('user_id', $user->getKey())->firstOrFail());
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
