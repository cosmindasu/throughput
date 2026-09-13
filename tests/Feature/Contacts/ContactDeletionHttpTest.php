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
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Ștergerea unui contact prin HTTP — specs.md §20.5 (RTBF), consecventă cu BR-CRM-01.
 * `App\Support\Contacts\ContactErasure` are propriile teste (`ContactErasureTest`);
 * aici: traducerea în mesaj flash, 404 pe un contact anonimizat, excluderea lui din
 * restul aplicației (`NotAnonymizedContactScope`) și păstrarea regulilor §7.5.
 */
class ContactDeletionHttpTest extends TestCase
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

        $this->clearDatabaseTenantContext();
    }

    public function test_a_contact_without_references_is_deleted_with_the_plain_message(): void
    {
        $contactId = TenantContext::run($this->marlin, fn () => $this->contact()->getKey());
        $this->clearDatabaseTenantContext();

        $this->actingAs($this->owner)->delete("/marlin/contacts/{$contactId}")
            ->assertRedirect('/marlin/contacts')
            ->assertSessionHas('success', 'Contact deleted.');

        TenantContext::run($this->marlin, function () use ($contactId): void {
            $this->assertDatabaseMissing('contacts', ['id' => $contactId]);
        });
    }

    public function test_a_contact_with_an_active_deal_is_anonymized_with_the_anonymized_message(): void
    {
        $contactId = TenantContext::run($this->marlin, function (): string {
            $contact = $this->contact();
            $this->dealFor($contact);

            return $contact->getKey();
        });
        $this->clearDatabaseTenantContext();

        $this->actingAs($this->owner)->delete("/marlin/contacts/{$contactId}")
            ->assertRedirect('/marlin/contacts')
            ->assertSessionHas(
                'success',
                'Contact anonymized — it is referenced by deals or orders, so its personal data was removed and the record kept.',
            );

        TenantContext::run($this->marlin, function () use ($contactId): void {
            $contact = Contact::withoutGlobalScope(NotAnonymizedContactScope::class)->findOrFail($contactId);
            $this->assertNotNull($contact->anonymized_at);
            $this->assertDatabaseHas('contacts', ['id' => $contactId]);
        });
    }

    public function test_a_contact_with_only_a_soft_deleted_deal_is_anonymized(): void
    {
        $contactId = TenantContext::run($this->marlin, function (): string {
            $contact = $this->contact();
            $this->dealFor($contact)->delete();

            return $contact->getKey();
        });
        $this->clearDatabaseTenantContext();

        $this->actingAs($this->owner)->delete("/marlin/contacts/{$contactId}")
            ->assertSessionHas(
                'success',
                'Contact anonymized — it is referenced by deals or orders, so its personal data was removed and the record kept.',
            );
    }

    public function test_a_contact_with_an_order_is_anonymized(): void
    {
        $contactId = TenantContext::run($this->marlin, function (): string {
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

            return $contact->getKey();
        });
        $this->clearDatabaseTenantContext();

        $this->actingAs($this->owner)->delete("/marlin/contacts/{$contactId}")
            ->assertSessionHas(
                'success',
                'Contact anonymized — it is referenced by deals or orders, so its personal data was removed and the record kept.',
            );
    }

    public function test_show_edit_update_and_destroy_of_an_anonymized_contact_are_not_found(): void
    {
        $contactId = TenantContext::run($this->marlin, function (): string {
            $contact = $this->contact();
            $this->dealFor($contact);
            ContactErasure::erase($contact->getKey());

            return $contact->getKey();
        });
        $this->clearDatabaseTenantContext();

        $this->actingAs($this->owner)->get("/marlin/contacts/{$contactId}")->assertNotFound();
        $this->actingAs($this->owner)->get("/marlin/contacts/{$contactId}/edit")->assertNotFound();
        $this->actingAs($this->owner)->put("/marlin/contacts/{$contactId}", [
            'account_id' => null,
            'first_name' => 'Changed',
            'last_name' => 'Name',
            'email' => null,
            'phone' => null,
            'title' => null,
            'is_primary' => false,
            'opt_out' => false,
        ])->assertNotFound();
        $this->actingAs($this->owner)->delete("/marlin/contacts/{$contactId}")->assertNotFound();
    }

    public function test_an_anonymized_contact_is_excluded_from_the_index_list(): void
    {
        TenantContext::run($this->marlin, function (): void {
            $this->contact(['email' => 'visible@northwind.test']);
            $toErase = $this->contact(['email' => 'erase.me@northwind.test']);
            $this->dealFor($toErase);
            ContactErasure::erase($toErase->getKey());
        });
        $this->clearDatabaseTenantContext();

        $this->actingAs($this->owner)->get('/marlin/contacts')
            ->assertInertia(fn (Assert $page) => $page
                ->loadDeferredProps(fn (Assert $page) => $page->has('contacts.data', 1))
            );
    }

    public function test_an_anonymized_contact_is_excluded_from_the_csv_export(): void
    {
        TenantContext::run($this->marlin, function (): void {
            $this->contact(['email' => 'visible@northwind.test']);
            $toErase = $this->contact(['email' => 'erase.me@northwind.test']);
            $this->dealFor($toErase);
            ContactErasure::erase($toErase->getKey());
        });
        $this->clearDatabaseTenantContext();

        $response = $this->actingAs($this->owner)->get('/marlin/contacts/export');
        $response->assertOk();

        $lines = array_filter(explode("\n", trim($response->getContent())));
        // Antet + doar contactul vizibil.
        $this->assertCount(2, $lines);
        $this->assertStringNotContainsString('Anonymized', $response->getContent());
    }

    public function test_an_anonymized_contact_is_excluded_from_global_search(): void
    {
        TenantContext::run($this->marlin, function (): void {
            $contact = $this->contact(['first_name' => 'Findable', 'last_name' => 'Person']);
            $this->dealFor($contact);
            ContactErasure::erase($contact->getKey());
        });
        $this->clearDatabaseTenantContext();

        $response = $this->actingAs($this->owner)->getJson('/marlin/search?q=findable');

        $response->assertOk();
        $types = collect($response->json('groups'))->pluck('type')->all();
        $this->assertNotContains('contacts', $types);
    }

    public function test_an_anonymized_contact_is_excluded_from_the_deal_primary_contact_options(): void
    {
        $accountId = TenantContext::run($this->marlin, function (): string {
            $this->contact(['email' => 'visible@northwind.test']);
            $toErase = $this->contact(['email' => 'erase.me@northwind.test']);
            $this->dealFor($toErase);
            ContactErasure::erase($toErase->getKey());

            return $this->account->getKey();
        });
        $this->clearDatabaseTenantContext();

        $this->actingAs($this->owner)
            ->get("/marlin/deals/create?account={$accountId}")
            ->assertInertia(fn (Assert $page) => $page
                ->component('Deals/Create')
                ->has('contacts', 1)
                ->where('contacts.0.name', 'Jane Doe')
            );
    }

    /**
     * §20.5 — un id de contact anonimizat, TRIMIS DIRECT (nu ales din dropdown), e
     * respins la validare: `Rule::exists()` rulează SQL brut și ocolește global
     * scope-ul Eloquent, deci fără `whereNull('anonymized_at')` explicit în
     * `StoreDealRequest`/`UpdateDealRequest` un id forjat ar fi trecut.
     */
    public function test_forging_an_anonymized_contact_as_the_primary_contact_on_create_is_rejected(): void
    {
        [$accountId, $anonymizedId] = TenantContext::run($this->marlin, function (): array {
            $toErase = $this->contact(['email' => 'erase.me@northwind.test']);
            $this->dealFor($toErase);
            ContactErasure::erase($toErase->getKey());

            return [$this->account->getKey(), $toErase->getKey()];
        });
        $this->clearDatabaseTenantContext();

        $this->actingAs($this->owner)
            ->from('/marlin/deals/create')
            ->post('/marlin/deals', [
                'account_id' => $accountId,
                'primary_contact_id' => $anonymizedId,
                'title' => 'Deal with an anonymized contact',
            ])
            ->assertSessionHasErrors('primary_contact_id');
    }

    public function test_forging_an_anonymized_contact_as_the_primary_contact_on_update_is_rejected(): void
    {
        [$dealId, $anonymizedId] = TenantContext::run($this->marlin, function (): array {
            $deal = $this->dealFor($this->contact(['email' => 'original@northwind.test']));

            $toErase = $this->contact(['email' => 'erase.me@northwind.test']);
            $this->dealFor($toErase);
            ContactErasure::erase($toErase->getKey());

            return [$deal->getKey(), $toErase->getKey()];
        });
        $this->clearDatabaseTenantContext();

        $this->actingAs($this->owner)
            ->from("/marlin/deals/{$dealId}/edit")
            ->put("/marlin/deals/{$dealId}", [
                'account_id' => $this->account->getKey(),
                'primary_contact_id' => $anonymizedId,
                'title' => 'Renewed',
            ])
            ->assertSessionHasErrors('primary_contact_id');
    }

    public function test_an_anonymized_contact_is_excluded_from_the_account_contacts_list(): void
    {
        $accountId = TenantContext::run($this->marlin, function (): string {
            $this->contact(['email' => 'visible@northwind.test']);
            $toErase = $this->contact(['email' => 'erase.me@northwind.test']);
            $this->dealFor($toErase);
            ContactErasure::erase($toErase->getKey());

            return $this->account->getKey();
        });
        $this->clearDatabaseTenantContext();

        $this->actingAs($this->owner)->get("/marlin/accounts/{$accountId}")
            ->assertInertia(fn (Assert $page) => $page
                ->component('Accounts/Show')
                ->has('contacts', 1)
            );
    }

    /**
     * Deviație de la restul aplicației: contactul principal anonimizat al unui deal
     * RĂMÂNE vizibil aici — text neutru (placeholder-ul de anonimizare), fără link
     * (`Deals/Show.tsx` nu a legat niciodată acest câmp) — altfel deal-ul ar arăta „—",
     * identic cu „n-a avut niciodată contact principal", ceea ce pierde informația.
     */
    public function test_an_anonymized_primary_contact_still_shows_as_neutral_text_on_the_deal_page(): void
    {
        $dealId = TenantContext::run($this->marlin, function (): string {
            $contact = $this->contact();
            $deal = $this->dealFor($contact);
            ContactErasure::erase($contact->getKey());

            return $deal->getKey();
        });
        $this->clearDatabaseTenantContext();

        $this->actingAs($this->owner)
            ->get("/marlin/deals/{$dealId}")
            ->assertInertia(fn (Assert $page) => $page
                ->component('Deals/Show')
                ->where('deal.primaryContact.name', 'Anonymized contact')
            );
    }

    /**
     * §20.5 — bugul găsit după fix: `edit()` nu încărca deloc contactul anonimizat, deci
     * `deal.primaryContact` era `null`, formularul pornea cu `primary_contact_id: ''`, iar
     * o editare de rutină (aici, doar titlul) trimitea `''` → `null`, golind tăcut FK-ul.
     * Cu bypass-ul de scope pe `edit()`, props-urile duc mai departe id-ul corect, iar
     * `keepsExistingPrimaryContact()` din `UpdateDealRequest` îl acceptă neschimbat.
     */
    public function test_editing_a_deal_keeps_the_anonymized_primary_contact_when_unchanged(): void
    {
        [$dealId, $anonymizedId] = TenantContext::run($this->marlin, function (): array {
            $contact = $this->contact();
            $deal = $this->dealFor($contact);
            ContactErasure::erase($contact->getKey());

            return [$deal->getKey(), $contact->getKey()];
        });
        $this->clearDatabaseTenantContext();

        $this->actingAs($this->owner)
            ->put("/marlin/deals/{$dealId}", [
                'account_id' => $this->account->getKey(),
                'primary_contact_id' => $anonymizedId,
                'title' => 'Renewed supply agreement',
            ])
            ->assertRedirect("/marlin/deals/{$dealId}")
            ->assertSessionDoesntHaveErrors();

        TenantContext::run($this->marlin, function () use ($dealId, $anonymizedId): void {
            $deal = Deal::query()->findOrFail($dealId);
            $this->assertSame('Renewed supply agreement', $deal->title);
            $this->assertSame($anonymizedId, $deal->primary_contact_id, 'FK-ul nu trebuie golit de o editare care nu atinge acest câmp.');
        });
    }

    /**
     * Props-urile de `Deals/Edit`: contactul anonimizat curent rămâne cel selectat
     * (`primaryContact.id`), marcat (`primaryContact.isAnonymized`), și absent din
     * `contacts` (opțiunile normale, `contactsForAccount()` — exclus ca oricare altul).
     */
    public function test_the_edit_page_exposes_the_current_anonymized_primary_contact_marked_as_such(): void
    {
        [$dealId, $anonymizedId] = TenantContext::run($this->marlin, function (): array {
            $contact = $this->contact();
            $deal = $this->dealFor($contact);
            ContactErasure::erase($contact->getKey());

            return [$deal->getKey(), $contact->getKey()];
        });
        $this->clearDatabaseTenantContext();

        $this->actingAs($this->owner)
            ->get("/marlin/deals/{$dealId}/edit")
            ->assertInertia(fn (Assert $page) => $page
                ->component('Deals/Edit')
                ->where('deal.primaryContact.id', $anonymizedId)
                ->where('deal.primaryContact.isAnonymized', true)
                ->where('contacts', [])
            );
    }

    /**
     * §7.5 rămâne neatins: `ContactPolicy::delete()` se evaluează ÎNAINTEA
     * `ContactErasure`, deci un Agent fără drepturi nu poate nici șterge, nici
     * anonimiza — contactul rămâne exact cum era.
     */
    public function test_an_agent_without_ownership_is_forbidden_and_the_contact_stays_untouched(): void
    {
        $agent = $this->makeMember($this->marlin, 'demo.agent@throughput.dev', Permissions::AGENT);

        $contactId = TenantContext::run($this->marlin, function (): string {
            $contact = $this->contact();
            $this->dealFor($contact);

            return $contact->getKey();
        });
        $this->clearDatabaseTenantContext();

        $this->actingAs($agent)->delete("/marlin/contacts/{$contactId}")->assertForbidden();

        TenantContext::run($this->marlin, function () use ($contactId): void {
            $contact = Contact::query()->findOrFail($contactId);
            $this->assertNull($contact->anonymized_at);
            $this->assertSame('Jane', $contact->first_name);
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
