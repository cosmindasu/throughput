<?php

namespace Tests\Feature\Contacts;

use App\Models\Account;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Order;
use App\Models\Pipeline;
use App\Models\Scopes\NotAnonymizedContactScope;
use App\Models\Stage;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Support\Contacts\ContactErasure;
use App\Support\Permissions;
use Tests\TestCase;

/**
 * `App\Support\Contacts\ContactErasure` — RTBF (Art. 17 GDPR, specs.md §20.5), testat
 * direct pe clasă, fără HTTP: `ContactController::destroy()` doar autorizează și
 * traduce rezultatul într-un mesaj flash (`ContactDeletionHttpTest` acoperă asta, plus
 * excluderile din restul aplicației).
 */
class ContactErasureTest extends TestCase
{
    private Tenant $marlin;

    private User $owner;

    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->marlin = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $this->owner = $this->makeMember($this->marlin, 'demo.owner@throughput.dev', Permissions::OWNER);

        TenantContext::run($this->marlin, function (): void {
            $account = new Account(['name' => 'Northwind Industrial Supply LLC']);
            $account->created_by = $this->owner->getKey();
            $account->save();
            $this->account = $account;
        });
    }

    public function test_a_contact_without_deals_or_orders_is_physically_deleted(): void
    {
        TenantContext::run($this->marlin, function (): void {
            $contact = $this->contact();

            $anonymized = ContactErasure::erase($contact->getKey());

            $this->assertFalse($anonymized);
            $this->assertModelMissing($contact);
        });
    }

    public function test_a_contact_with_an_active_deal_is_anonymized_and_the_fk_stays_intact(): void
    {
        TenantContext::run($this->marlin, function (): void {
            $contact = $this->contact(['is_primary' => true]);
            $deal = $this->dealFor($contact);

            $anonymized = ContactErasure::erase($contact->getKey());

            $this->assertTrue($anonymized);

            $fresh = Contact::withoutGlobalScope(NotAnonymizedContactScope::class)->findOrFail($contact->getKey());
            $this->assertSame('Anonymized', $fresh->first_name);
            $this->assertSame('contact', $fresh->last_name);
            $this->assertNull($fresh->email);
            $this->assertNull($fresh->phone);
            $this->assertNull($fresh->title);
            $this->assertFalse($fresh->is_primary);
            $this->assertTrue($fresh->opt_out);
            $this->assertNotNull($fresh->anonymized_at);
            $this->assertTrue($fresh->isAnonymized());

            // FK-ul deal-ului spre contact rămâne intact — integritatea referențială a
            // istoricului, §20.5.
            $this->assertSame($contact->getKey(), $deal->fresh()->primary_contact_id);
        });
    }

    public function test_a_contact_with_only_a_soft_deleted_deal_is_still_anonymized(): void
    {
        TenantContext::run($this->marlin, function (): void {
            $contact = $this->contact();
            $deal = $this->dealFor($contact);
            $deal->delete();

            $anonymized = ContactErasure::erase($contact->getKey());

            $this->assertTrue($anonymized, 'BR-CRM-01: deal-urile șterse (soft delete) se numără și ele.');
            $this->assertSame($contact->getKey(), $deal->fresh()->primary_contact_id);
        });
    }

    public function test_a_contact_with_an_order_is_anonymized(): void
    {
        TenantContext::run($this->marlin, function (): void {
            $contact = $this->contact();

            $order = new Order([
                'account_id' => $this->account->getKey(),
                'contact_id' => $contact->getKey(),
                'owner_user_id' => $this->owner->getKey(),
                'status' => Order::STATUS_DRAFT,
                'currency' => 'USD',
            ]);
            $order->created_by = $this->owner->getKey();
            $order->save();

            $anonymized = ContactErasure::erase($contact->getKey());

            $this->assertTrue($anonymized);
            $this->assertSame($contact->getKey(), $order->fresh()->contact_id);
        });
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function contact(array $overrides = []): Contact
    {
        $contact = new Contact(array_merge([
            'account_id' => $this->account->getKey(),
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'email' => 'jane.doe@northwind.test',
            'phone' => '555-0100',
            'title' => 'Buyer',
        ], $overrides));
        $contact->created_by = $this->owner->getKey();
        $contact->save();

        return $contact;
    }

    private function dealFor(Contact $contact): Deal
    {
        $pipeline = Pipeline::query()->create(['name' => 'Standard']);
        $stage = Stage::query()->create(['pipeline_id' => $pipeline->getKey(), 'name' => 'Qualification', 'position' => 1]);

        $deal = new Deal([
            'account_id' => $this->account->getKey(),
            'primary_contact_id' => $contact->getKey(),
            'pipeline_id' => $pipeline->getKey(),
            'stage_id' => $stage->getKey(),
            'owner_user_id' => $this->owner->getKey(),
            'title' => 'Annual supply agreement',
            'status' => Deal::STATUS_OPEN,
        ]);
        $deal->created_by = $this->owner->getKey();
        $deal->save();

        return $deal;
    }
}
