<?php

namespace Tests\Feature\Contacts;

use App\Models\Account;
use App\Models\Contact;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Support\Permissions;
use Tests\TestCase;

/**
 * P1-002 (code review pachetul „contacte") — acces direct (IDOR) pe un id de contact
 * dintr-un alt tenant. ADR-003: o scurgere cere ambele straturi să greșească simultan
 * — global scope-ul Eloquent ascunde rândul din `Contact::find()`, iar chiar dacă
 * cineva ar ocoli Eloquent, RLS oprește citirea la nivel de bază.
 *
 * Contextul de tenant se golește ÎNAINTEA fiecărei cereri (`clearDatabaseTenantContext`,
 * `tests/TestCase.php`): altfel tranzacția de test ar păstra contextul lăsat de
 * `TenantContext::run()` folosit ca să creeze contactul „străin", iar testul ar trece
 * dintr-un motiv greșit.
 */
class ContactIsolationTest extends TestCase
{
    private Tenant $marlin;

    private Tenant $cascade;

    private User $owner;

    private Contact $foreignContact;

    protected function setUp(): void
    {
        parent::setUp();

        $this->marlin = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $this->cascade = $this->makeTenant('cascade', 'Cascade Hydraulic Components');
        $this->owner = $this->makeMember($this->marlin, 'demo.owner@throughput.dev', Permissions::OWNER);

        $strangerOwner = $this->makeMember($this->cascade, 'demo.owner@throughput.dev', Permissions::OWNER, user: $this->owner);

        $this->foreignContact = TenantContext::run($this->cascade, function () use ($strangerOwner): Contact {
            $account = new Account(['name' => 'Cascade Bearing Co.']);
            $account->created_by = $strangerOwner->getKey();
            $account->save();

            $contact = new Contact([
                'account_id' => $account->getKey(),
                'first_name' => 'Alex',
                'last_name' => 'Stranger',
                'email' => 'alex.stranger@cascade.test',
            ]);
            $contact->created_by = $strangerOwner->getKey();
            $contact->save();

            return $contact;
        });

        $this->clearDatabaseTenantContext();
    }

    public function test_show_of_a_contact_from_another_tenant_is_not_found(): void
    {
        $this->actingAs($this->owner)
            ->get("/marlin/contacts/{$this->foreignContact->id}")
            ->assertNotFound();
    }

    public function test_edit_of_a_contact_from_another_tenant_is_not_found(): void
    {
        $this->actingAs($this->owner)
            ->get("/marlin/contacts/{$this->foreignContact->id}/edit")
            ->assertNotFound();
    }

    public function test_update_of_a_contact_from_another_tenant_is_not_found(): void
    {
        $this->actingAs($this->owner)
            ->put("/marlin/contacts/{$this->foreignContact->id}", [
                'account_id' => null,
                'first_name' => 'Changed',
                'last_name' => 'Name',
                'email' => null,
                'phone' => null,
                'title' => null,
                'is_primary' => false,
                'opt_out' => false,
            ])
            ->assertNotFound();
    }

    public function test_destroy_of_a_contact_from_another_tenant_is_not_found(): void
    {
        $this->actingAs($this->owner)
            ->delete("/marlin/contacts/{$this->foreignContact->id}")
            ->assertNotFound();

        TenantContext::run($this->cascade, function (): void {
            $this->assertDatabaseHas('contacts', ['email' => 'alex.stranger@cascade.test']);
        });
    }
}
