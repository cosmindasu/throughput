<?php

namespace Tests\Feature\Contacts;

use App\Models\Account;
use App\Models\Contact;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Support\Contacts\PrimaryContactAssignment;
use App\Support\Permissions;
use Tests\TestCase;

/**
 * FR-CRM-02 — un singur contact `is_primary` per cont, prin cererea HTTP completă
 * (Store + Update) și direct pe `PrimaryContactAssignment` pentru cazul de
 * concurență, care nu e observabil printr-o singură cerere secvențială.
 */
class PrimaryContactTest extends TestCase
{
    private Tenant $marlin;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->marlin = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $this->owner = $this->makeMember($this->marlin, 'demo.owner@throughput.dev', Permissions::OWNER);
    }

    public function test_marking_a_new_contact_primary_demotes_the_previous_one(): void
    {
        [$accountId, $previousPrimaryId] = TenantContext::run($this->marlin, function (): array {
            $account = $this->account('Northwind Industrial Supply LLC');
            $previous = $this->contact($account, 'Jane', 'Doe', primary: true);

            return [$account->id, $previous->id];
        });

        $this->actingAs($this->owner)->post('/marlin/contacts', $this->payload([
            'account_id' => $accountId,
            'first_name' => 'John',
            'last_name' => 'Smith',
            'is_primary' => true,
        ]))->assertRedirect();

        TenantContext::run($this->marlin, function () use ($accountId, $previousPrimaryId): void {
            $this->assertFalse(Contact::query()->findOrFail($previousPrimaryId)->is_primary);

            $newPrimary = Contact::query()->where('account_id', $accountId)->where('first_name', 'John')->firstOrFail();
            $this->assertTrue($newPrimary->is_primary);

            // Exact UN singur primary a rămas pe cont.
            $this->assertSame(1, Contact::query()->where('account_id', $accountId)->where('is_primary', true)->count());
        });
    }

    public function test_updating_a_contact_to_primary_demotes_the_previous_one(): void
    {
        [$accountId, $previousPrimaryId, $contactId] = TenantContext::run($this->marlin, function (): array {
            $account = $this->account('Northwind Industrial Supply LLC');
            $previous = $this->contact($account, 'Jane', 'Doe', primary: true);
            $other = $this->contact($account, 'John', 'Smith', primary: false);

            return [$account->id, $previous->id, $other->id];
        });

        $this->actingAs($this->owner)->put("/marlin/contacts/{$contactId}", $this->payload([
            'account_id' => $accountId,
            'first_name' => 'John',
            'last_name' => 'Smith',
            'is_primary' => true,
        ]))->assertRedirect("/marlin/contacts/{$contactId}");

        TenantContext::run($this->marlin, function () use ($previousPrimaryId, $contactId, $accountId): void {
            $this->assertFalse(Contact::query()->findOrFail($previousPrimaryId)->is_primary);
            $this->assertTrue(Contact::query()->findOrFail($contactId)->is_primary);
            $this->assertSame(1, Contact::query()->where('account_id', $accountId)->where('is_primary', true)->count());
        });
    }

    public function test_moving_a_primary_contact_to_another_account_demotes_the_targets_primary(): void
    {
        [$targetAccountId, $movingContactId, $targetPreviousPrimaryId] = TenantContext::run($this->marlin, function (): array {
            $sourceAccount = $this->account('Northwind Industrial Supply LLC');
            $targetAccount = $this->account('Cascade Bearing Co.');

            $moving = $this->contact($sourceAccount, 'Jane', 'Doe', primary: true);
            $targetPrimary = $this->contact($targetAccount, 'John', 'Smith', primary: true);

            return [$targetAccount->id, $moving->id, $targetPrimary->id];
        });

        $this->actingAs($this->owner)->put("/marlin/contacts/{$movingContactId}", $this->payload([
            'account_id' => $targetAccountId,
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'is_primary' => true,
        ]))->assertRedirect("/marlin/contacts/{$movingContactId}");

        TenantContext::run($this->marlin, function () use ($movingContactId, $targetPreviousPrimaryId, $targetAccountId): void {
            $moved = Contact::query()->findOrFail($movingContactId);
            $this->assertSame($targetAccountId, $moved->account_id);
            $this->assertTrue($moved->is_primary);

            $this->assertFalse(Contact::query()->findOrFail($targetPreviousPrimaryId)->is_primary);
            $this->assertSame(1, Contact::query()->where('account_id', $targetAccountId)->where('is_primary', true)->count());
        });
    }

    public function test_a_primary_contact_must_belong_to_an_account(): void
    {
        $response = $this->actingAs($this->owner)
            ->from('/marlin/contacts/create')
            ->post('/marlin/contacts', $this->payload(['account_id' => null, 'is_primary' => true]));

        $response->assertSessionHasErrors('is_primary');

        TenantContext::run($this->marlin, function (): void {
            $this->assertSame(0, Contact::query()->count());
        });
    }

    /**
     * `PrimaryContactAssignment` izolat de HTTP (plan §1.2): două apeluri secvențiale
     * pe același cont nu lasă niciodată doi primari. Garanția sub concurență reală —
     * `Account::lockForUpdate()` serializează a doua tranzacție în spatele primei —
     * ține de proprietatea SQL a `SELECT ... FOR UPDATE`, neobservabilă într-un test
     * cu un singur fir de execuție; e documentată în docblock-ul clasei, nu aici.
     */
    public function test_two_sequential_assignments_on_the_same_account_never_leave_two_primaries(): void
    {
        [$accountId, $firstContactId, $secondContactId] = TenantContext::run($this->marlin, function (): array {
            $account = $this->account('Northwind Industrial Supply LLC');
            $first = $this->contact($account, 'Jane', 'Doe', primary: false);
            $second = $this->contact($account, 'John', 'Smith', primary: false);

            return [$account->id, $first->id, $second->id];
        });

        TenantContext::run($this->marlin, function () use ($accountId, $firstContactId, $secondContactId): void {
            PrimaryContactAssignment::apply($accountId, $firstContactId);
            Contact::query()->whereKey($firstContactId)->update(['is_primary' => true]);

            PrimaryContactAssignment::apply($accountId, $secondContactId);
            Contact::query()->whereKey($secondContactId)->update(['is_primary' => true]);

            $this->assertFalse(Contact::query()->findOrFail($firstContactId)->is_primary);
            $this->assertTrue(Contact::query()->findOrFail($secondContactId)->is_primary);
            $this->assertSame(1, Contact::query()->where('account_id', $accountId)->where('is_primary', true)->count());
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
            'email' => null,
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

    private function contact(Account $account, string $firstName, string $lastName, bool $primary): Contact
    {
        $contact = new Contact([
            'account_id' => $account->getKey(),
            'first_name' => $firstName,
            'last_name' => $lastName,
            'is_primary' => $primary,
        ]);
        $contact->created_by = $this->owner->getKey();
        $contact->save();

        return $contact;
    }
}
