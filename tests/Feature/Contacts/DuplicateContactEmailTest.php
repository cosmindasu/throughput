<?php

namespace Tests\Feature\Contacts;

use App\Models\Account;
use App\Models\Contact;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Support\Contacts\DuplicateContactEmail;
use App\Support\Permissions;
use Illuminate\Support\Facades\Validator;
use Inertia\Support\SessionKey;
use Tests\TestCase;

/**
 * US-CRM-01 — avertismentul de email duplicat, verificat direct pe validator. Fluxul HTTP
 * complet (formularul care îl afișează) e acoperit de testele modulelor care îl folosesc.
 */
class DuplicateContactEmailTest extends TestCase
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

    public function test_an_email_linked_to_another_account_stops_the_save_with_a_link_to_it(): void
    {
        TenantContext::run($this->marlin, function (): void {
            $this->contactAt($this->account('Northwind Industrial Supply LLC'), 'jane.doe@northwind.test');

            $validator = $this->validate('Jane.Doe@Northwind.test', confirmed: false);

            $this->assertSame(
                ['This email is already linked to Northwind Industrial Supply LLC.'],
                $validator->errors()->get('contact.email')
            );
            $this->assertSame('Northwind Industrial Supply LLC', session(SessionKey::FLASH_DATA)[DuplicateContactEmail::FLASH_KEY]['accountName']);
        });
    }

    public function test_an_explicit_confirmation_lets_the_save_through(): void
    {
        TenantContext::run($this->marlin, function (): void {
            $this->contactAt($this->account('Northwind Industrial Supply LLC'), 'jane.doe@northwind.test');

            $this->assertTrue($this->validate('jane.doe@northwind.test', confirmed: true)->errors()->isEmpty());
        });
    }

    public function test_the_same_email_in_another_workspace_is_not_a_duplicate(): void
    {
        TenantContext::run($this->cascade, fn () => $this->contactAt($this->account('Cascade Hydraulics Group Inc.'), 'jane.doe@northwind.test'));

        TenantContext::run($this->marlin, function (): void {
            $this->assertTrue($this->validate('jane.doe@northwind.test', confirmed: false)->errors()->isEmpty());
        });
    }

    public function test_editing_a_contact_does_not_flag_its_own_email(): void
    {
        TenantContext::run($this->marlin, function (): void {
            $contact = $this->contactAt($this->account('Northwind Industrial Supply LLC'), 'jane.doe@northwind.test');

            $this->assertTrue($this->validate('jane.doe@northwind.test', confirmed: false, except: $contact->id)->errors()->isEmpty());
        });
    }

    private function validate(string $email, bool $confirmed, ?string $except = null): \Illuminate\Validation\Validator
    {
        $validator = Validator::make(['contact' => ['email' => $email]], ['contact.email' => ['email']]);

        $validator->after(fn ($validator) => DuplicateContactEmail::check($validator, 'contact.email', $email, $confirmed, $except));
        $validator->passes();

        return $validator;
    }

    private function account(string $name): Account
    {
        $account = new Account(['name' => $name]);
        $account->created_by = $this->owner->getKey();
        $account->save();

        return $account;
    }

    private function contactAt(Account $account, string $email): Contact
    {
        $contact = new Contact(['account_id' => $account->id, 'first_name' => 'Jane', 'last_name' => 'Doe', 'email' => $email]);
        $contact->created_by = $this->owner->getKey();
        $contact->save();

        return $contact;
    }
}
