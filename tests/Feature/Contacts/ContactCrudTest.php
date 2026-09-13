<?php

namespace Tests\Feature\Contacts;

use App\Models\Account;
use App\Models\Contact;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Support\Permissions;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * FR-CRM-02, §7.5 — CRUD de contacte per rol, prin cererea HTTP reală. §7.3 cere ca
 * un buton fără drept să LIPSEASCĂ, nu doar să respingă serverul — de-aia fiecare
 * scenariu verifică ȘI propul `can`, nu doar codul de stare.
 */
class ContactCrudTest extends TestCase
{
    private Tenant $marlin;

    private User $owner;

    private User $manager;

    private User $agent;

    private User $viewer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->marlin = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $this->owner = $this->makeMember($this->marlin, 'demo.owner@throughput.dev', Permissions::OWNER);
        $this->manager = $this->makeMember($this->marlin, 'demo.manager@throughput.dev', Permissions::MANAGER);
        $this->agent = $this->makeMember($this->marlin, 'demo.agent@throughput.dev', Permissions::AGENT);
        $this->viewer = $this->makeMember($this->marlin, 'demo.viewer@throughput.dev', Permissions::VIEWER);
    }

    public function test_a_viewer_sees_no_write_actions_and_is_refused_on_write(): void
    {
        $contact = TenantContext::run(
            $this->marlin,
            fn () => $this->contact($this->account('Northwind Industrial Supply LLC'), $this->owner)
        );

        $this->actingAs($this->viewer)->get('/marlin/contacts')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Contacts/Index')
                ->where('can.create', false)
            );

        $this->actingAs($this->viewer)->get("/marlin/contacts/{$contact->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('can.edit', false)
                ->where('can.delete', false)
            );

        $this->actingAs($this->viewer)->get('/marlin/contacts/create')->assertForbidden();
        $this->actingAs($this->viewer)->post('/marlin/contacts', $this->payload())->assertForbidden();
        $this->actingAs($this->viewer)->get("/marlin/contacts/{$contact->id}/edit")->assertForbidden();
        $this->actingAs($this->viewer)->put("/marlin/contacts/{$contact->id}", $this->payload())->assertForbidden();
        $this->actingAs($this->viewer)->delete("/marlin/contacts/{$contact->id}")->assertForbidden();
    }

    public function test_an_agent_can_edit_and_delete_a_contact_they_created(): void
    {
        $account = TenantContext::run($this->marlin, fn () => $this->account('Northwind Industrial Supply LLC'));

        $this->actingAs($this->agent)
            ->post('/marlin/contacts', $this->payload(['account_id' => $account->id]))
            ->assertRedirect();

        $contact = TenantContext::run($this->marlin, fn () => Contact::query()->where('email', 'jane.doe@northwind.test')->firstOrFail());
        $this->assertSame($this->agent->getKey(), $contact->created_by);

        $this->actingAs($this->agent)->get("/marlin/contacts/{$contact->id}")
            ->assertInertia(fn (Assert $page) => $page->where('can.edit', true)->where('can.delete', true));

        $this->actingAs($this->agent)
            ->put("/marlin/contacts/{$contact->id}", $this->payload(['account_id' => $account->id, 'title' => 'Updated title']))
            ->assertRedirect("/marlin/contacts/{$contact->id}");

        $this->actingAs($this->agent)->delete("/marlin/contacts/{$contact->id}")->assertRedirect('/marlin/contacts');

        TenantContext::run($this->marlin, function () use ($contact): void {
            $this->assertModelMissing($contact);
        });
    }

    public function test_an_agent_cannot_edit_a_contact_created_by_someone_else_on_an_unowned_account(): void
    {
        $contact = TenantContext::run($this->marlin, function () {
            $account = $this->account('Northwind Industrial Supply LLC');

            return $this->contact($account, $this->manager);
        });

        $this->actingAs($this->agent)->get("/marlin/contacts/{$contact->id}")
            ->assertInertia(fn (Assert $page) => $page->where('can.edit', false)->where('can.delete', false));

        $this->actingAs($this->agent)->get("/marlin/contacts/{$contact->id}/edit")->assertForbidden();
        $this->actingAs($this->agent)->put("/marlin/contacts/{$contact->id}", $this->payload())->assertForbidden();
        $this->actingAs($this->agent)->delete("/marlin/contacts/{$contact->id}")->assertForbidden();
    }

    public function test_an_agent_can_edit_a_contact_whose_account_they_own(): void
    {
        $contact = TenantContext::run($this->marlin, function () {
            $account = new Account(['name' => 'Northwind Industrial Supply LLC', 'owner_user_id' => $this->agent->getKey()]);
            $account->created_by = $this->manager->getKey();
            $account->save();

            // Creat de Manager, dar contul ARE agentul ca responsabil — §7.5.
            return $this->contact($account, $this->manager);
        });

        $this->actingAs($this->agent)->get("/marlin/contacts/{$contact->id}")
            ->assertInertia(fn (Assert $page) => $page->where('can.edit', true)->where('can.delete', true));

        $this->actingAs($this->agent)
            ->put("/marlin/contacts/{$contact->id}", $this->payload(['account_id' => $contact->account_id, 'title' => 'Buyer']))
            ->assertRedirect("/marlin/contacts/{$contact->id}");
    }

    public function test_creating_a_contact_with_an_account_from_another_tenant_is_rejected(): void
    {
        $cascade = $this->makeTenant('cascade', 'Cascade Hydraulic Components');
        $foreignAccountId = TenantContext::run($cascade, function () use ($cascade) {
            $owner = $this->makeMember($cascade, 'demo.owner@throughput.dev', Permissions::OWNER, user: $this->owner);
            $account = new Account(['name' => 'Cascade Bearing Co.']);
            $account->created_by = $owner->getKey();
            $account->save();

            return $account->id;
        });

        $response = $this->actingAs($this->owner)
            ->from('/marlin/contacts/create')
            ->post('/marlin/contacts', $this->payload(['account_id' => $foreignAccountId]));

        $response->assertRedirect('/marlin/contacts/create');
        $response->assertSessionHasErrors('account_id');

        TenantContext::run($this->marlin, function (): void {
            $this->assertDatabaseMissing('contacts', ['email' => 'jane.doe@northwind.test']);
        });
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'account_id' => null,
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'email' => 'jane.doe@northwind.test',
            'phone' => null,
            'title' => null,
            'is_primary' => false,
            'opt_out' => false,
        ], $overrides);
    }

    private function account(string $name): Account
    {
        $account = new Account(['name' => $name]);
        $account->created_by = $this->owner->getKey();
        $account->save();

        return $account;
    }

    private function contact(Account $account, User $creator): Contact
    {
        $contact = new Contact([
            'account_id' => $account->getKey(),
            'first_name' => 'Jane',
            'last_name' => 'Doe',
        ]);
        $contact->created_by = $creator->getKey();
        $contact->save();

        return $contact;
    }
}
