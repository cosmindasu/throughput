<?php

namespace Tests\Feature\Members;

use App\Enums\OrderStatus;
use App\Models\Account;
use App\Models\Deal;
use App\Models\Membership;
use App\Models\Order;
use App\Models\Stage;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Support\Permissions;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesPipelines;
use Tests\TestCase;

/**
 * FR-TEN-05 — vederea „Unassigned" (§6.4.1, ADR-011): deals deschise + comenzi active ale
 * membrilor dezactivați, NU conturi (decizie deja luată). RBAC (Owner/Manager), indicatorul
 * numeric partajat, și reatribuirea în masă pe întreaga vedere.
 */
class UnassignedViewTest extends TestCase
{
    use CreatesPipelines;

    private Tenant $marlin;

    private User $owner;

    private Account $account;

    private Stage $stage;

    protected function setUp(): void
    {
        parent::setUp();

        config(['throughput.demo.mode' => false]);

        $this->marlin = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $this->owner = $this->makeMember($this->marlin, 'owner@throughput.dev', Permissions::OWNER);

        TenantContext::run($this->marlin, function (): void {
            $pipeline = $this->makeDefaultPipeline($this->marlin);
            $this->stage = $pipeline['stages']['New'];

            $account = new Account(['name' => 'Northwind Industrial Supply LLC']);
            $account->created_by = $this->owner->getKey();
            $account->save();
            $this->account = $account;
        });

        $this->clearDatabaseTenantContext();
    }

    public function test_an_agent_cannot_open_unassigned(): void
    {
        $agent = $this->makeMember($this->marlin, 'agent@throughput.dev', Permissions::AGENT);

        $this->actingAs($agent)->get('/marlin/unassigned')->assertForbidden();
    }

    public function test_a_viewer_cannot_open_unassigned(): void
    {
        $viewer = $this->makeMember($this->marlin, 'viewer@throughput.dev', Permissions::VIEWER);

        $this->actingAs($viewer)->get('/marlin/unassigned')->assertForbidden();
    }

    public function test_a_manager_sees_open_deals_and_active_orders_of_deactivated_members_but_no_accounts(): void
    {
        $jane = $this->makeMember($this->marlin, 'jane@throughput.dev', Permissions::AGENT);
        $manager = $this->makeMember($this->marlin, 'manager@throughput.dev', Permissions::MANAGER);

        TenantContext::run($this->marlin, function () use ($jane): void {
            $this->makeDeal($jane, Deal::STATUS_OPEN, 'Open deal');
            $this->makeDeal($jane, Deal::STATUS_WON, 'Won deal');
            $this->makeOrder($jane, OrderStatus::Confirmed);
            $this->makeOrder($jane, OrderStatus::Fulfilled);

            $account = new Account(['name' => 'Janes account', 'owner_user_id' => $jane->getKey()]);
            $account->created_by = $this->owner->getKey();
            $account->save();
        });
        $this->clearDatabaseTenantContext();

        $this->actingAs($this->owner)->post(
            "/marlin/settings/members/{$this->membershipOf($jane)->getKey()}/deactivate",
            ['reassign' => false],
        );

        $this->actingAs($manager)
            ->get('/marlin/unassigned')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Unassigned/Index')
                // `deals`/`orders` sunt props deferred (FR-PERF-01) — cerute explicit.
                ->loadDeferredProps(fn (Assert $deferred) => $deferred
                    ->has('deals.data', 1)
                    ->where('deals.data.0.title', 'Open deal')
                    ->has('orders.data', 1)
                )
            );
    }

    public function test_the_navigation_indicator_counts_open_deals_and_active_orders_only(): void
    {
        $jane = $this->makeMember($this->marlin, 'jane@throughput.dev', Permissions::AGENT);

        TenantContext::run($this->marlin, function () use ($jane): void {
            $this->makeDeal($jane, Deal::STATUS_OPEN, 'Deal A');
            $this->makeDeal($jane, Deal::STATUS_OPEN, 'Deal B');
            $this->makeOrder($jane, OrderStatus::Draft);
        });
        $this->clearDatabaseTenantContext();

        $this->actingAs($this->owner)->post(
            "/marlin/settings/members/{$this->membershipOf($jane)->getKey()}/deactivate",
            ['reassign' => false],
        );

        $this->actingAs($this->owner)
            ->get('/marlin/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('unassignedRecordsCount', 3));
    }

    /**
     * Agent/Viewer nu au `unassigned.view` — indicatorul rămâne 0, fără eroare, chiar
     * dacă tenantul are înregistrări neatribuite.
     */
    public function test_the_navigation_indicator_is_zero_for_roles_without_access(): void
    {
        $jane = $this->makeMember($this->marlin, 'jane@throughput.dev', Permissions::AGENT);
        $agent = $this->makeMember($this->marlin, 'agent2@throughput.dev', Permissions::AGENT);

        TenantContext::run($this->marlin, fn () => $this->makeDeal($jane, Deal::STATUS_OPEN, 'Deal A'));
        $this->clearDatabaseTenantContext();

        $this->actingAs($this->owner)->post(
            "/marlin/settings/members/{$this->membershipOf($jane)->getKey()}/deactivate",
            ['reassign' => false],
        );

        $this->actingAs($agent)
            ->get('/marlin/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('unassignedRecordsCount', 0));
    }

    public function test_reassigning_from_unassigned_moves_every_open_deal_and_active_order_under_one_group(): void
    {
        $jane = $this->makeMember($this->marlin, 'jane@throughput.dev', Permissions::AGENT);
        $bob = $this->makeMember($this->marlin, 'bob@throughput.dev', Permissions::AGENT);
        $manager = $this->makeMember($this->marlin, 'manager@throughput.dev', Permissions::MANAGER);
        $newOwner = $this->makeMember($this->marlin, 'newowner@throughput.dev', Permissions::MANAGER);

        // Două ORFANE de la DOI membri dezactivați diferiți — vederea „Unassigned" nu e
        // legată de un singur membru.
        TenantContext::run($this->marlin, function () use ($jane, $bob): void {
            $this->makeDeal($jane, Deal::STATUS_OPEN, 'From Jane');
            $this->makeDeal($bob, Deal::STATUS_OPEN, 'From Bob');
        });
        $this->clearDatabaseTenantContext();

        $this->actingAs($this->owner)->post("/marlin/settings/members/{$this->membershipOf($jane)->getKey()}/deactivate", ['reassign' => false]);
        $this->actingAs($this->owner)->post("/marlin/settings/members/{$this->membershipOf($bob)->getKey()}/deactivate", ['reassign' => false]);

        $response = $this->actingAs($manager)->post('/marlin/unassigned/reassign', ['new_owner_user_id' => $newOwner->getKey()]);

        $response->assertRedirect();
        $this->assertStringContainsString('/marlin/bulk/groups/', $response->headers->get('Location'));

        $this->drainBulkQueue();

        $reassigned = TenantContext::run($this->marlin, fn () => Deal::query()->where('owner_user_id', $newOwner->getKey())->count());
        $this->assertSame(2, $reassigned);
    }

    /**
     * Audit de accesibilitate (P1, pct. 1) — `Unassigned/Index.tsx` leagă acum eroarea de
     * `new_owner_user_id` prin `Field`, nu doar prin banner-ul global de `flash.error`.
     */
    public function test_reassigning_to_someone_outside_the_workspace_returns_a_field_error(): void
    {
        $manager = $this->makeMember($this->marlin, 'manager@throughput.dev', Permissions::MANAGER);
        $outsider = User::query()->create(['name' => 'Outsider', 'email' => 'outsider@throughput.dev', 'password' => 'password']);

        $response = $this->actingAs($manager)->post('/marlin/unassigned/reassign', ['new_owner_user_id' => $outsider->getKey()]);

        $response->assertSessionHasErrors([
            'new_owner_user_id' => 'The selected owner is not an active member of this workspace.',
        ]);
    }

    /**
     * FR-I18N-04, Lotul I18N Val 5 (a treia trecere) — `rules.members.owner_not_active`,
     * mesajul era literal englez direct în `ReassignUnassignedRequest`.
     */
    public function test_reassigning_to_someone_outside_the_workspace_message_translates_to_french(): void
    {
        $manager = $this->makeMember($this->marlin, 'manager@throughput.dev', Permissions::MANAGER);
        $manager->forceFill(['locale' => 'fr'])->save();
        $outsider = User::query()->create(['name' => 'Outsider', 'email' => 'outsider@throughput.dev', 'password' => 'password']);

        $response = $this->actingAs($manager)->post('/marlin/unassigned/reassign', ['new_owner_user_id' => $outsider->getKey()]);

        $response->assertSessionHasErrors([
            'new_owner_user_id' => 'Le propriétaire sélectionné n’est pas membre actif de cet espace de travail.',
        ]);
    }

    /**
     * Drenează coada `bulk` până se golește — planificatorul, chunk-urile ȘI jobul de
     * finalizare, ca într-un worker real (la fel ca `MemberDeactivationTest`).
     */
    private function drainBulkQueue(): void
    {
        $this->clearDatabaseTenantContext();

        $this->artisan('queue:work', [
            '--queue' => 'bulk',
            '--stop-when-empty' => true,
            '--no-interaction' => true,
        ]);
    }

    private function membershipOf(User $user): Membership
    {
        return TenantContext::run($this->marlin, fn () => Membership::query()->where('user_id', $user->getKey())->firstOrFail());
    }

    private function makeDeal(User $owner, string $status, string $title): Deal
    {
        $deal = new Deal([
            'account_id' => $this->account->getKey(),
            'pipeline_id' => $this->stage->pipeline_id,
            'stage_id' => $this->stage->getKey(),
            'owner_user_id' => $owner->getKey(),
            'title' => $title,
            'status' => $status,
        ]);
        $deal->created_by = $owner->getKey();
        $deal->save();

        return $deal;
    }

    private function makeOrder(User $owner, OrderStatus $status): Order
    {
        $order = new Order([
            'account_id' => $this->account->getKey(),
            'owner_user_id' => $owner->getKey(),
            'status' => $status,
            'currency' => 'USD',
        ]);
        $order->created_by = $owner->getKey();
        $order->save();

        return $order;
    }
}
