<?php

namespace Tests\Feature\Members;

use App\Enums\OrderStatus;
use App\Models\Account;
use App\Models\ActivityLog;
use App\Models\BulkOperation;
use App\Models\Deal;
use App\Models\Membership;
use App\Models\Order;
use App\Models\Stage;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\MembershipRecordsNeedNewOwnerNotification;
use App\Services\Tenancy\TenantContext;
use App\Support\Permissions;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\CreatesPipelines;
use Tests\TestCase;

/**
 * US-TEN-03, §6.4.1 — dezactivarea unui membru: revocarea accesului (BR-TEN-03/04),
 * confirmarea cu numerele exacte (BR-TEN-06), cele două căi din dialog („Reassign and
 * deactivate" / „Deactivate anyway"), și jurnalul de activitate (§17). Ultimul Owner e
 * acoperit deja de `MembershipPolicyTest` (Policy pur); aici e efectul HTTP complet.
 */
class MemberDeactivationTest extends TestCase
{
    use CreatesPipelines;

    private Tenant $marlin;

    private User $owner;

    private User $manager;

    private Account $account;

    private Stage $stage;

    protected function setUp(): void
    {
        parent::setUp();

        // `.env.testing` are DEMO_MODE=true (implicit de producție — §22). Ruta de
        // dezactivare e păzită de acest guardrail (raportul pachetului); testele DE AICI
        // verifică fluxul REAL, nu refuzul — acela are propriul test, în
        // `MembersDemoModeGuardrailTest`.
        config(['throughput.demo.mode' => false]);

        $this->marlin = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $this->owner = $this->makeMember($this->marlin, 'owner@throughput.dev', Permissions::OWNER);
        $this->manager = $this->makeMember($this->marlin, 'manager@throughput.dev', Permissions::MANAGER);

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

    public function test_deactivating_a_member_revokes_access_starting_with_their_next_request(): void
    {
        $jane = $this->makeMember($this->marlin, 'jane@throughput.dev', Permissions::AGENT);

        $this->actingAs($jane)->get('/marlin/dashboard')->assertOk();

        $this->actingAs($this->owner)
            ->post("/marlin/settings/members/{$this->membershipOf($jane)->getKey()}/deactivate", ['reassign' => false])
            ->assertRedirect('/marlin/settings/members');

        // Jane însăși: comutatorul de workspace nu mai găsește tenantul (ADR-011 —
        // `forCurrentUserAcrossTenants()` filtrează pe `status = active`).
        $this->actingAs($jane)->get('/marlin/dashboard')->assertNotFound();

        $membership = TenantContext::run($this->marlin, fn () => $this->membershipOf($jane)->fresh());
        $this->assertSame(Membership::STATUS_DEACTIVATED, $membership->status);
        $this->assertNotNull($membership->deactivated_at);
        $this->assertSame($this->owner->getKey(), $membership->deactivated_by);
        // BR-TEN-04 — rândul rămâne, cu rolul intact (nu un DELETE).
        $this->assertNotNull($membership->id);
    }

    public function test_the_activity_log_records_old_and_new_status_values(): void
    {
        $jane = $this->makeMember($this->marlin, 'jane@throughput.dev', Permissions::AGENT);

        $this->actingAs($this->owner)->post("/marlin/settings/members/{$this->membershipOf($jane)->getKey()}/deactivate", ['reassign' => false]);

        $log = TenantContext::run($this->marlin, fn () => ActivityLog::query()
            ->where('auditable_type', Membership::class)
            ->where('auditable_id', $this->membershipOf($jane)->getKey())
            ->sole());

        $this->assertSame('updated', $log->action);
        $this->assertSame(Membership::STATUS_ACTIVE, $log->old_values['status']);
        $this->assertSame(Membership::STATUS_DEACTIVATED, $log->new_values['status']);
        $this->assertSame($this->owner->getKey(), $log->user_id);
    }

    /**
     * BR-TEN-06 — „Deactivate anyway": deals deschise + comenzi active rămân vizibile
     * (Unassigned), conturile rămân atribuite (fără reasignare tăcută), iar notificarea
     * pleacă spre TOȚI Owner-ii activi.
     */
    public function test_deactivate_anyway_leaves_records_unassigned_and_notifies_active_owners(): void
    {
        Notification::fake();

        $jane = $this->makeMember($this->marlin, 'jane@throughput.dev', Permissions::AGENT);
        $secondOwner = $this->makeMember($this->marlin, 'second.owner@throughput.dev', Permissions::OWNER);

        TenantContext::run($this->marlin, function () use ($jane): void {
            $this->makeDeal($jane, Deal::STATUS_OPEN);
            $this->makeDeal($jane, Deal::STATUS_OPEN);
            $this->makeDeal($jane, Deal::STATUS_WON); // închis — nu se numără
            $this->makeOrder($jane, OrderStatus::Confirmed);
            $this->makeOrder($jane, OrderStatus::Fulfilled); // închis — nu se numără

            $account = new Account(['name' => 'Janes Own Account', 'owner_user_id' => $jane->getKey()]);
            $account->created_by = $this->owner->getKey();
            $account->save();
        });
        $this->clearDatabaseTenantContext();

        $this->actingAs($this->owner)
            ->post("/marlin/settings/members/{$this->membershipOf($jane)->getKey()}/deactivate", ['reassign' => false])
            ->assertRedirect('/marlin/settings/members')
            // ADR-022, Lot I18N Val 2 — textul a trecut de la „3 record(s)" (ternar/
            // concatenare) la `trans_choice()` pe cheia `flash.members.
            // deactivated_with_open_records`: „3 records" (plural corect), nu mai
            // „record(s)" cu paranteză.
            ->assertSessionHas('success', fn (string $message) => str_contains($message, '3 records need a new owner'));

        // Conturile lui Jane rămân atribuite ei (decizie deja luată — NU intră în calcul).
        $janeAccounts = TenantContext::run($this->marlin, fn () => Account::query()->where('owner_user_id', $jane->getKey())->count());
        $this->assertSame(1, $janeAccounts);

        // Deals/orders ÎNCHISE rămân neatinse, tot pe Jane.
        $closedStillOnJane = TenantContext::run($this->marlin, fn () => Deal::query()->where('status', Deal::STATUS_WON)->where('owner_user_id', $jane->getKey())->count()
            + Order::query()->where('status', OrderStatus::Fulfilled)->where('owner_user_id', $jane->getKey())->count());
        $this->assertSame(2, $closedStillOnJane);

        Notification::assertSentTo([$this->owner, $secondOwner], MembershipRecordsNeedNewOwnerNotification::class);
        Notification::assertNotSentTo($jane, MembershipRecordsNeedNewOwnerNotification::class);
    }

    public function test_deactivate_anyway_sends_no_notification_when_there_is_nothing_open(): void
    {
        Notification::fake();

        $jane = $this->makeMember($this->marlin, 'jane@throughput.dev', Permissions::AGENT);

        $this->actingAs($this->owner)->post("/marlin/settings/members/{$this->membershipOf($jane)->getKey()}/deactivate", ['reassign' => false]);

        Notification::assertNothingSent();
    }

    /**
     * BR-BULK-04, plan §9 — „Reassign and deactivate": trei opérations (accounts, deals,
     * orders), un `group_id` comun; înregistrările ÎNCHISE nu se ating.
     */
    public function test_reassign_and_deactivate_moves_open_records_under_one_group_id_and_leaves_closed_records_on_the_old_owner(): void
    {
        $jane = $this->makeMember($this->marlin, 'jane@throughput.dev', Permissions::AGENT);
        $newOwner = $this->makeMember($this->marlin, 'newowner@throughput.dev', Permissions::MANAGER);

        TenantContext::run($this->marlin, function () use ($jane): void {
            $this->makeDeal($jane, Deal::STATUS_OPEN);
            $this->makeDeal($jane, Deal::STATUS_LOST);
            $this->makeOrder($jane, OrderStatus::Draft);
            $this->makeOrder($jane, OrderStatus::Cancelled);

            $account = new Account(['name' => 'Janes Own Account', 'owner_user_id' => $jane->getKey(), 'status' => Account::STATUS_ACTIVE]);
            $account->created_by = $this->owner->getKey();
            $account->save();

            $archived = new Account(['name' => 'Old archived account', 'owner_user_id' => $jane->getKey(), 'status' => Account::STATUS_INACTIVE]);
            $archived->created_by = $this->owner->getKey();
            $archived->save();
        });
        $this->clearDatabaseTenantContext();

        $response = $this->actingAs($this->owner)->post(
            "/marlin/settings/members/{$this->membershipOf($jane)->getKey()}/deactivate",
            ['reassign' => true, 'new_owner_user_id' => $newOwner->getKey()],
        );

        $operations = TenantContext::run($this->marlin, fn () => BulkOperation::query()->get());
        $this->assertCount(3, $operations);
        $groupIds = $operations->pluck('group_id')->unique();
        $this->assertCount(1, $groupIds, 'Toate trei operațiile trebuie să împartă ACELAȘI group_id (BR-BULK-04).');

        $response->assertRedirect('/marlin/bulk/groups/'.$groupIds->first());

        $this->drainBulkQueue();

        // Deschise/active → reasignate.
        $this->assertSame($newOwner->getKey(), TenantContext::run($this->marlin, fn () => Deal::query()->where('status', Deal::STATUS_OPEN)->sole()->owner_user_id));
        $this->assertSame($newOwner->getKey(), TenantContext::run($this->marlin, fn () => Order::query()->where('status', OrderStatus::Draft)->sole()->owner_user_id));
        $this->assertSame($newOwner->getKey(), TenantContext::run($this->marlin, fn () => Account::query()->where('status', Account::STATUS_ACTIVE)->where('name', 'Janes Own Account')->sole()->owner_user_id));

        // Închise/arhivate → NEATINSE, rămân pe Jane.
        $this->assertSame($jane->getKey(), TenantContext::run($this->marlin, fn () => Deal::query()->where('status', Deal::STATUS_LOST)->sole()->owner_user_id));
        $this->assertSame($jane->getKey(), TenantContext::run($this->marlin, fn () => Order::query()->where('status', OrderStatus::Cancelled)->sole()->owner_user_id));
        $this->assertSame($jane->getKey(), TenantContext::run($this->marlin, fn () => Account::query()->where('status', Account::STATUS_INACTIVE)->sole()->owner_user_id));

        // Dezactivarea s-a întâmplat ÎN ACEEAȘI tranzacție.
        $membership = TenantContext::run($this->marlin, fn () => $this->membershipOf($jane)->fresh());
        $this->assertSame(Membership::STATUS_DEACTIVATED, $membership->status);
    }

    public function test_deactivating_the_last_owner_is_blocked_with_no_force_option(): void
    {
        $response = $this->actingAs($this->owner)
            ->from('/marlin/settings/members')
            ->post("/marlin/settings/members/{$this->membershipOf($this->owner)->getKey()}/deactivate", ['reassign' => false]);

        $response->assertRedirect('/marlin/settings/members');
        // Audit de accesibilitate (P1) — `withErrors()`, nu `flash.error`: vezi
        // comentariul din `MembersController::deactivate()`.
        $response->assertSessionHasErrors(['deactivate' => 'Transfer ownership before deactivating the last Owner.']);

        $membership = TenantContext::run($this->marlin, fn () => $this->membershipOf($this->owner)->fresh());
        $this->assertSame(Membership::STATUS_ACTIVE, $membership->status, 'Ultimul Owner rămâne activ — blocarea e absolută.');
    }

    public function test_a_manager_cannot_deactivate_an_owner_over_http(): void
    {
        $this->makeMember($this->marlin, 'second.owner@throughput.dev', Permissions::OWNER);

        $response = $this->actingAs($this->manager)
            ->from('/marlin/settings/members')
            ->post("/marlin/settings/members/{$this->membershipOf($this->owner)->getKey()}/deactivate", ['reassign' => false]);

        $response->assertSessionHasErrors(['deactivate' => 'Only an Owner can deactivate another Owner.']);
    }

    /**
     * P2-001 (review general) — idempotență: al doilea POST pe un membership deja
     * dezactivat (dublu-click, retry) nu trebuie să rescrie `deactivated_at`/
     * `deactivated_by`, să scrie un al doilea rând în `activity_log`, sau să retrimită
     * notificarea către Owner-i.
     */
    public function test_a_second_deactivation_of_the_same_member_is_refused(): void
    {
        Notification::fake();

        $jane = $this->makeMember($this->marlin, 'jane@throughput.dev', Permissions::AGENT);
        TenantContext::run($this->marlin, fn () => $this->makeDeal($jane, Deal::STATUS_OPEN));
        $this->clearDatabaseTenantContext();

        $first = $this->actingAs($this->owner)
            ->post("/marlin/settings/members/{$this->membershipOf($jane)->getKey()}/deactivate", ['reassign' => false]);
        $first->assertRedirect('/marlin/settings/members');

        $membershipAfterFirst = TenantContext::run($this->marlin, fn () => $this->membershipOf($jane)->fresh());
        $deactivatedAtAfterFirst = $membershipAfterFirst->deactivated_at;
        $this->assertNotNull($deactivatedAtAfterFirst);

        Notification::assertSentTimes(MembershipRecordsNeedNewOwnerNotification::class, 1);

        $second = $this->actingAs($this->owner)
            ->post("/marlin/settings/members/{$this->membershipOf($jane)->getKey()}/deactivate", ['reassign' => false]);
        $second->assertSessionHasErrors(['deactivate' => 'This member is already deactivated.']);

        // Nicio a doua notificare, niciun al doilea grup, `deactivated_at` neschimbat.
        Notification::assertSentTimes(MembershipRecordsNeedNewOwnerNotification::class, 1);
        $this->assertSame(0, TenantContext::run($this->marlin, fn () => BulkOperation::query()->count()));

        $membershipAfterSecond = TenantContext::run($this->marlin, fn () => $this->membershipOf($jane)->fresh());
        $this->assertTrue($deactivatedAtAfterFirst->equalTo($membershipAfterSecond->deactivated_at));

        $logCount = TenantContext::run($this->marlin, fn () => ActivityLog::query()
            ->where('auditable_type', Membership::class)
            ->where('auditable_id', $membershipAfterSecond->getKey())
            ->count());
        $this->assertSame(1, $logCount, 'Al doilea POST nu trebuie să scrie un al doilea rând în activity_log.');
    }

    /**
     * P2-004 (review general) — decizie: auto-dezactivarea e blocată. Ownerul e AL DOILEA
     * activ (nu ultimul), ca refuzul să vină din regula nouă, nu din BR-TEN-01.
     */
    public function test_a_member_cannot_deactivate_themselves(): void
    {
        $secondOwner = $this->makeMember($this->marlin, 'second.owner@throughput.dev', Permissions::OWNER);

        $response = $this->actingAs($secondOwner)
            ->post("/marlin/settings/members/{$this->membershipOf($secondOwner)->getKey()}/deactivate", ['reassign' => false]);

        $response->assertSessionHasErrors(['deactivate' => "You can't deactivate yourself. Ask another Owner or Manager."]);

        $membership = TenantContext::run($this->marlin, fn () => $this->membershipOf($secondOwner)->fresh());
        $this->assertSame(Membership::STATUS_ACTIVE, $membership->status);
    }

    /**
     * P3(d) (review general) — `{membership}` dintr-un ALT tenant nu trebuie nici măcar
     * confirmat că există (§18.5, aceeași regulă ca restul rutelor cu binding de model).
     */
    public function test_deactivating_a_membership_from_another_tenant_is_a_404(): void
    {
        $otherTenant = $this->makeTenant('cascade', 'Cascade Hydraulic Components');
        $foreignAgent = $this->makeMember($otherTenant, 'foreign.agent@throughput.dev', Permissions::AGENT);
        $foreignMembershipId = $this->membershipOf($foreignAgent, $otherTenant)->getKey();

        $this->actingAs($this->owner)
            ->post("/marlin/settings/members/{$foreignMembershipId}/deactivate", ['reassign' => false])
            ->assertNotFound();

        $membership = TenantContext::run($otherTenant, fn () => Membership::query()->findOrFail($foreignMembershipId)->fresh());
        $this->assertSame(Membership::STATUS_ACTIVE, $membership->status, 'Membership-ul din alt tenant nu trebuie atins.');
    }

    /**
     * Audit de accesibilitate (P1, pct. 1) — eroarea de câmp trebuie să ajungă sub cheia
     * `new_owner_user_id` (nu `deactivate`), ca `DeactivateMemberDialog` s-o lege de
     * select-ul „New owner" prin `Field`, nu s-o arate ca alertă generică.
     */
    public function test_reassigning_to_someone_outside_the_workspace_returns_a_field_error(): void
    {
        $jane = $this->makeMember($this->marlin, 'jane@throughput.dev', Permissions::AGENT);
        $outsider = User::query()->create(['name' => 'Outsider', 'email' => 'outsider@throughput.dev', 'password' => 'password']);

        $response = $this->actingAs($this->owner)->post(
            "/marlin/settings/members/{$this->membershipOf($jane)->getKey()}/deactivate",
            ['reassign' => true, 'new_owner_user_id' => $outsider->getKey()],
        );

        $response->assertSessionHasErrors([
            'new_owner_user_id' => 'The selected owner is not an active member of this workspace.',
        ]);

        $membership = TenantContext::run($this->marlin, fn () => $this->membershipOf($jane)->fresh());
        $this->assertSame(Membership::STATUS_ACTIVE, $membership->status, 'O reasignare refuzată nu trebuie să dezactiveze membrul.');
    }

    public function test_reassigning_to_the_member_being_deactivated_returns_a_field_error(): void
    {
        $jane = $this->makeMember($this->marlin, 'jane@throughput.dev', Permissions::AGENT);

        $response = $this->actingAs($this->owner)->post(
            "/marlin/settings/members/{$this->membershipOf($jane)->getKey()}/deactivate",
            ['reassign' => true, 'new_owner_user_id' => $jane->getKey()],
        );

        $response->assertSessionHasErrors([
            'new_owner_user_id' => 'The new owner cannot be the member being deactivated.',
        ]);
    }

    /**
     * FR-I18N-04, Lotul I18N Val 5 (a treia trecere) — `rules.members.owner_not_active`,
     * mesajul era literal englez direct în `DeactivateMembershipRequest`.
     */
    public function test_reassigning_to_someone_outside_the_workspace_message_translates_to_french(): void
    {
        $jane = $this->makeMember($this->marlin, 'jane@throughput.dev', Permissions::AGENT);
        $outsider = User::query()->create(['name' => 'Outsider', 'email' => 'outsider@throughput.dev', 'password' => 'password']);
        $this->owner->forceFill(['locale' => 'fr'])->save();

        $response = $this->actingAs($this->owner)->post(
            "/marlin/settings/members/{$this->membershipOf($jane)->getKey()}/deactivate",
            ['reassign' => true, 'new_owner_user_id' => $outsider->getKey()],
        );

        $response->assertSessionHasErrors([
            'new_owner_user_id' => 'Le propriétaire sélectionné n’est pas membre actif de cet espace de travail.',
        ]);
    }

    /**
     * FR-I18N-04, Lotul I18N Val 5 (a treia trecere) — `rules.members.new_owner_is_target`,
     * mesajul era literal englez direct în `DeactivateMembershipRequest`.
     */
    public function test_reassigning_to_the_member_being_deactivated_message_translates_to_french(): void
    {
        $jane = $this->makeMember($this->marlin, 'jane@throughput.dev', Permissions::AGENT);
        $this->owner->forceFill(['locale' => 'fr'])->save();

        $response = $this->actingAs($this->owner)->post(
            "/marlin/settings/members/{$this->membershipOf($jane)->getKey()}/deactivate",
            ['reassign' => true, 'new_owner_user_id' => $jane->getKey()],
        );

        $response->assertSessionHasErrors([
            'new_owner_user_id' => 'Le nouveau propriétaire ne peut pas être le membre en cours de désactivation.',
        ]);
    }

    private function membershipOf(User $user, ?Tenant $tenant = null): Membership
    {
        return TenantContext::run($tenant ?? $this->marlin, fn () => Membership::query()->where('user_id', $user->getKey())->firstOrFail());
    }

    private function makeDeal(User $owner, string $status): Deal
    {
        $deal = new Deal([
            'account_id' => $this->account->getKey(),
            'pipeline_id' => $this->stage->pipeline_id,
            'stage_id' => $this->stage->getKey(),
            'owner_user_id' => $owner->getKey(),
            'title' => 'Test deal',
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
