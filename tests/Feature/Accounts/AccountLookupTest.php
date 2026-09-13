<?php

namespace Tests\Feature\Accounts;

use App\Models\Account;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Support\Permissions;
use Database\Factories\AccountFactory;
use Tests\TestCase;

/**
 * P2-001 (code review pachetul „contacte") — `GET /{w}/accounts/lookup`, endpoint-ul
 * din spatele `AccountCombobox`. Rezultate, limită, autorizare și izolare de tenant pe
 * ambele straturi (ADR-003) — RLS ar opri singur o scurgere, dar global scope-ul
 * Eloquent trebuie să o oprească deja, altfel ar rămâne o singură plasă.
 */
class AccountLookupTest extends TestCase
{
    private Tenant $marlin;

    private Tenant $cascade;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->marlin = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $this->cascade = $this->makeTenant('cascade', 'Cascade Hydraulic Components');
        $this->owner = $this->makeMember($this->marlin, 'demo.owner@throughput.dev', Permissions::OWNER);
    }

    public function test_it_returns_matching_accounts_ordered_by_name(): void
    {
        TenantContext::run($this->marlin, function (): void {
            $this->account('Zenith Fastener Supply');
            $this->account('Acme Bearing Distribution');
            $this->account('Acme Hydraulics Group');
        });

        $response = $this->actingAs($this->owner)->getJson('/marlin/accounts/lookup?q=acme');

        $response->assertOk();
        $names = collect($response->json('data'))->pluck('name')->all();

        $this->assertSame(['Acme Bearing Distribution', 'Acme Hydraulics Group'], $names);
    }

    public function test_it_caps_results_at_twenty(): void
    {
        TenantContext::run($this->marlin, function (): void {
            (new AccountFactory)->count(25)->create(['created_by' => $this->owner->getKey(), 'name' => fn () => 'Torque '.fake()->unique()->company()]);
        });

        $response = $this->actingAs($this->owner)->getJson('/marlin/accounts/lookup?q=Torque');

        $response->assertOk();
        $this->assertCount(20, $response->json('data'));
    }

    public function test_it_does_not_leak_accounts_from_another_tenant(): void
    {
        $strangerOwner = $this->makeMember($this->cascade, 'demo.owner@throughput.dev', Permissions::OWNER, user: $this->owner);

        TenantContext::run($this->cascade, function () use ($strangerOwner): void {
            $account = new Account(['name' => 'Acme Cascade Components']);
            $account->created_by = $strangerOwner->getKey();
            $account->save();
        });

        TenantContext::run($this->marlin, function (): void {
            $this->account('Acme Marlin Fasteners');
        });

        $response = $this->actingAs($this->owner)->getJson('/marlin/accounts/lookup?q=Acme');

        $response->assertOk();
        $names = collect($response->json('data'))->pluck('name')->all();

        $this->assertSame(['Acme Marlin Fasteners'], $names);
    }

    public function test_it_is_refused_without_the_accounts_view_permission(): void
    {
        // Membership activ, dar FĂRĂ rol atribuit — situația în care `Gate::authorize`
        // trebuie să respingă, distinct de „utilizator complet străin de tenant"
        // (acoperit de `ContactIsolationTest`/`AccountShowTest`).
        $roleless = User::query()->create([
            'name' => 'Roleless Person',
            'email' => 'roleless@throughput.dev',
            'password' => 'password',
        ]);

        TenantContext::run($this->marlin, function () use ($roleless): void {
            Membership::query()->create([
                'user_id' => $roleless->getKey(),
                'status' => Membership::STATUS_ACTIVE,
            ]);
        });

        $this->actingAs($roleless)->getJson('/marlin/accounts/lookup?q=acme')->assertForbidden();
    }

    private function account(string $name): Account
    {
        $account = new Account(['name' => $name]);
        $account->created_by = $this->owner->getKey();
        $account->save();

        return $account;
    }
}
