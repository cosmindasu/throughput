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
 * US-CRM-01 — avertismentul de email duplicat, prin cererea HTTP completă (Store +
 * Update). `App\Support\Contacts\DuplicateContactEmail` e deja acoperit izolat de
 * `DuplicateContactEmailTest` (fundația Fazei 2) — aici se verifică doar firul de
 * capăt la capăt: fără confirmare nu se scrie nimic, cu `confirm_duplicate_email`
 * salvarea trece.
 */
class DuplicateContactEmailHttpTest extends TestCase
{
    private Tenant $marlin;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->marlin = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $this->owner = $this->makeMember($this->marlin, 'demo.owner@throughput.dev', Permissions::OWNER);
    }

    public function test_saving_a_duplicate_email_without_confirmation_is_refused(): void
    {
        [$existingAccountId] = TenantContext::run($this->marlin, function (): array {
            $account = $this->account('Northwind Industrial Supply LLC');
            $this->contact($account, 'jane.doe@northwind.test');

            return [$account->id];
        });

        $response = $this->actingAs($this->owner)
            ->from('/marlin/contacts/create')
            ->post('/marlin/contacts', $this->payload([
                'email' => 'Jane.Doe@Northwind.test',
                'confirm_duplicate_email' => false,
            ]));

        $response->assertRedirect('/marlin/contacts/create');
        $response->assertSessionHasErrors('email');

        TenantContext::run($this->marlin, function () use ($existingAccountId): void {
            // Un singur contact cu acest email — nimic nu s-a scris fără confirmare.
            $this->assertSame(1, Contact::query()->where('email', 'jane.doe@northwind.test')->count());
            $this->assertNotNull($existingAccountId);
        });
    }

    public function test_confirming_the_duplicate_lets_the_save_through(): void
    {
        TenantContext::run($this->marlin, function (): void {
            $this->contact($this->account('Northwind Industrial Supply LLC'), 'jane.doe@northwind.test');
        });

        $response = $this->actingAs($this->owner)->post('/marlin/contacts', $this->payload([
            'email' => 'jane.doe@northwind.test',
            'confirm_duplicate_email' => true,
        ]));

        $response->assertRedirect();
        $response->assertSessionDoesntHaveErrors();

        TenantContext::run($this->marlin, function (): void {
            $this->assertSame(2, Contact::query()->where('email', 'jane.doe@northwind.test')->count());
        });
    }

    public function test_editing_a_contact_does_not_flag_its_own_email_as_duplicate(): void
    {
        $contact = TenantContext::run($this->marlin, fn () => $this->contact(
            $this->account('Northwind Industrial Supply LLC'),
            'jane.doe@northwind.test'
        ));

        $response = $this->actingAs($this->owner)->put("/marlin/contacts/{$contact->id}", $this->payload([
            'account_id' => $contact->account_id,
            'email' => 'jane.doe@northwind.test',
            'title' => 'Updated title',
            'confirm_duplicate_email' => false,
        ]));

        $response->assertRedirect("/marlin/contacts/{$contact->id}");
        $response->assertSessionDoesntHaveErrors();
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
            'email' => null,
            'phone' => null,
            'title' => null,
            'is_primary' => false,
            'opt_out' => false,
            'confirm_duplicate_email' => false,
        ], $overrides);
    }

    private function account(string $name): Account
    {
        $account = new Account(['name' => $name]);
        $account->created_by = $this->owner->getKey();
        $account->save();

        return $account;
    }

    private function contact(Account $account, string $email): Contact
    {
        $contact = new Contact([
            'account_id' => $account->getKey(),
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'email' => $email,
        ]);
        $contact->created_by = $this->owner->getKey();
        $contact->save();

        return $contact;
    }
}
