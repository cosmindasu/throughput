<?php

namespace Tests\Feature\Accounts;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Support\Permissions;
use Database\Factories\AccountFactory;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * TEST-05 (audit 2026-09-23, §11-teste.md) — `accounts.edit` (GET) n-avea NICIUN test: nici
 * contractul Inertia, nici 403 fără permisiune. `AccountCrudTest` acoperă `PUT
 * /accounts/{account}` (autorizare + ownership Agent), niciodată pagina GET care randează
 * formularul. Simetric cu `AccountShowTest::test_the_page_has_the_expected_contract...`.
 */
class AccountEditPageTest extends TestCase
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

    public function test_the_edit_page_has_the_expected_contract(): void
    {
        $account = TenantContext::run($this->marlin, fn () => (new AccountFactory)->create([
            'name' => 'Northwind Industrial Supply',
            'created_by' => $this->owner->getKey(),
        ]));
        $this->clearDatabaseTenantContext();

        $this->actingAs($this->owner)->get("/marlin/accounts/{$account->id}/edit")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Accounts/Edit')
                ->where('account.id', $account->id)
                ->where('account.name', 'Northwind Industrial Supply')
                ->has('owners')
            );
    }

    public function test_a_viewer_without_accounts_edit_cannot_view_the_edit_page(): void
    {
        $account = TenantContext::run($this->marlin, fn () => (new AccountFactory)->create(['created_by' => $this->owner->getKey()]));
        $viewer = $this->makeMember($this->marlin, 'demo.viewer@throughput.dev', Permissions::VIEWER);
        $this->clearDatabaseTenantContext();

        $this->actingAs($viewer)->get("/marlin/accounts/{$account->id}/edit")->assertForbidden();
    }

    /**
     * §7.5 — ABAC identică cu `AccountPolicy::update()`, deja verificată pe PUT
     * (`AccountCrudTest::test_an_agent_can_update_only_accounts_they_own_or_created`); aici
     * pe GET, ruta care randează formularul — un Agent care apasă „Edit" pe un cont străin
     * nu trebuie să vadă formularul deloc.
     */
    public function test_an_agent_who_does_not_own_the_account_cannot_view_the_edit_page(): void
    {
        $agent = $this->makeMember($this->marlin, 'demo.agent@throughput.dev', Permissions::AGENT);
        $foreign = TenantContext::run($this->marlin, fn () => (new AccountFactory)->create([
            'created_by' => $this->owner->getKey(),
            'owner_user_id' => $this->owner->getKey(),
        ]));
        $this->clearDatabaseTenantContext();

        $this->actingAs($agent)->get("/marlin/accounts/{$foreign->id}/edit")->assertForbidden();
    }
}
