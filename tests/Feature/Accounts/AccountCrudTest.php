<?php

namespace Tests\Feature\Accounts;

use App\Models\Account;
use App\Models\Contact;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Support\Permissions;
use Database\Factories\AccountFactory;
use Tests\TestCase;

/**
 * FR-CRM-01, US-CRM-01 — creare (cu contact principal opțional, duplicat de email),
 * validare, editare restrânsă la §7.5 pentru Agent.
 */
class AccountCrudTest extends TestCase
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

    public function test_creating_an_account_with_a_primary_contact_in_the_same_transaction(): void
    {
        $response = $this->actingAs($this->owner)->post('/marlin/accounts', [
            'name' => 'Northwind Industrial Supply LLC',
            'status' => 'prospect',
            'credit_terms' => 'net_30',
            'contact' => [
                'first_name' => 'Jane',
                'last_name' => 'Doe',
                'email' => 'Jane.Doe@Northwind.test',
            ],
        ]);

        $response->assertRedirect();

        $account = TenantContext::run($this->marlin, fn () => Account::query()->where('name', 'Northwind Industrial Supply LLC')->firstOrFail());

        $this->assertSame($this->owner->getKey(), $account->owner_user_id, 'Owner-ul implicit e utilizatorul curent.');

        $contact = TenantContext::run($this->marlin, fn () => Contact::query()->where('account_id', $account->getKey())->firstOrFail());

        $this->assertTrue((bool) $contact->is_primary);
        $this->assertSame('jane.doe@northwind.test', $contact->email, 'Emailul se normalizează la litere mici.');
    }

    public function test_a_duplicate_contact_email_stops_the_save_until_confirmed(): void
    {
        TenantContext::run($this->marlin, function (): void {
            $existing = (new AccountFactory)->create(['name' => 'Existing Co', 'created_by' => $this->owner->getKey()]);
            $contact = new Contact(['account_id' => $existing->getKey(), 'first_name' => 'Jane', 'last_name' => 'Doe', 'email' => 'jane.doe@existing.test']);
            $contact->created_by = $this->owner->getKey();
            $contact->save();
        });
        $this->clearDatabaseTenantContext();

        $payload = [
            'name' => 'New Account',
            'status' => 'prospect',
            'credit_terms' => 'net_30',
            'contact' => ['first_name' => 'Jane', 'last_name' => 'Doe', 'email' => 'jane.doe@existing.test'],
        ];

        $this->actingAs($this->owner)->post('/marlin/accounts', $payload)
            ->assertSessionHasErrors('contact.email');

        $this->clearDatabaseTenantContext();
        $this->assertFalse(
            TenantContext::run($this->marlin, fn () => Account::query()->where('name', 'New Account')->exists()),
            'Nimic nu se scrie până la confirmarea explicită.'
        );

        $this->actingAs($this->owner)->post('/marlin/accounts', [...$payload, 'confirm_duplicate_email' => true])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertTrue(TenantContext::run($this->marlin, fn () => Account::query()->where('name', 'New Account')->exists()));
    }

    public function test_validation_requires_a_name(): void
    {
        $this->actingAs($this->owner)->post('/marlin/accounts', ['status' => 'prospect', 'credit_terms' => 'net_30'])
            ->assertSessionHasErrors('name');
    }

    public function test_an_agent_can_update_only_accounts_they_own_or_created(): void
    {
        $agent = $this->makeMember($this->marlin, 'demo.agent@throughput.dev', Permissions::AGENT);

        $own = TenantContext::run($this->marlin, fn () => (new AccountFactory)->create(['created_by' => $agent->getKey(), 'owner_user_id' => $agent->getKey()]));
        $foreign = TenantContext::run($this->marlin, fn () => (new AccountFactory)->create(['created_by' => $this->owner->getKey(), 'owner_user_id' => $this->owner->getKey()]));
        $this->clearDatabaseTenantContext();

        $this->actingAs($agent)->put("/marlin/accounts/{$own->id}", [
            'name' => 'Updated by owner-agent',
            'status' => 'active',
            'credit_terms' => 'net_30',
        ])->assertRedirect();

        $this->actingAs($agent)->put("/marlin/accounts/{$foreign->id}", [
            'name' => 'Should be forbidden',
            'status' => 'active',
            'credit_terms' => 'net_30',
        ])->assertForbidden();
    }

    /**
     * §7.4 — Manager are CRUD complet, nu doar pe „propriile" conturi (spre deosebire de
     * Agent, testat mai sus).
     */
    public function test_a_manager_can_update_any_account_regardless_of_ownership(): void
    {
        $manager = $this->makeMember($this->marlin, 'demo.manager@throughput.dev', Permissions::MANAGER);

        $ownedByOwner = TenantContext::run($this->marlin, fn () => (new AccountFactory)->create(['created_by' => $this->owner->getKey(), 'owner_user_id' => $this->owner->getKey()]));
        $this->clearDatabaseTenantContext();

        $this->actingAs($manager)->put("/marlin/accounts/{$ownedByOwner->id}", [
            'name' => 'Updated by manager',
            'status' => 'active',
            'credit_terms' => 'net_30',
        ])->assertRedirect();
    }

    public function test_the_owner_dropdown_only_accepts_active_members(): void
    {
        $formerMember = $this->makeMember($this->marlin, 'left@throughput.dev', Permissions::AGENT);
        TenantContext::run($this->marlin, function () use ($formerMember): void {
            Membership::query()->where('user_id', $formerMember->getKey())->update(['status' => Membership::STATUS_DEACTIVATED]);
        });
        $this->clearDatabaseTenantContext();

        $this->actingAs($this->owner)->post('/marlin/accounts', [
            'name' => 'Owner validation test',
            'status' => 'prospect',
            'credit_terms' => 'net_30',
            'owner_user_id' => $formerMember->getKey(),
        ])->assertSessionHasErrors('owner_user_id');
    }
}
