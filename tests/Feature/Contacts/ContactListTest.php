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
 * FR-CRM-02, plan §8 — lista de contacte, prin cererea HTTP reală (auth →
 * SetSessionContext → ResolveWorkspace → policy → props). `contacts` e un prop
 * deferred (FR-PERF-01): trebuie cerut explicit cu `loadDeferredProps()` (capcana
 * (c) din raport), altfel testul ar „trece" fără să fi văzut vreun rând.
 */
class ContactListTest extends TestCase
{
    private Tenant $marlin;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->marlin = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $this->owner = $this->makeMember($this->marlin, 'demo.owner@throughput.dev', Permissions::OWNER);
    }

    public function test_the_index_page_is_a_deferred_cursor_contract(): void
    {
        TenantContext::run($this->marlin, function (): void {
            $account = $this->account('Northwind Industrial Supply LLC');
            $this->contact($account, 'Jane', 'Doe');
            $this->contact($account, 'John', 'Smith');
        });

        $response = $this->actingAs($this->owner)->get('/marlin/contacts');

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Contacts/Index')
            ->has('list')
            ->where('list.sort', 'last_name')
            ->has('can.create')
            ->where('can.create', true)
            // Deferred: NU trimis pe randarea inițială (FR-PERF-01) — shell-ul
            // paginii vine fără rândurile de tabel.
            ->missing('contacts')
        );

        $response->assertInertia(fn (Assert $page) => $page
            ->component('Contacts/Index')
            ->loadDeferredProps(fn (Assert $page) => $page
                ->has('contacts.data', 2)
                ->has('contacts.data.0.id')
                ->has('contacts.data.0.fullName')
                ->has('contacts.data.0.can.edit')
                ->where('contacts.nextCursor', null)
            )
        );
    }

    public function test_the_search_filter_matches_first_last_or_email(): void
    {
        TenantContext::run($this->marlin, function (): void {
            $account = $this->account('Northwind Industrial Supply LLC');
            $this->contact($account, 'Jane', 'Doe', 'jane.doe@northwind.test');
            $this->contact($account, 'John', 'Smith', 'john.smith@northwind.test');
        });

        $this->actingAs($this->owner)->get('/marlin/contacts?filter[q]=jane')
            ->assertInertia(fn (Assert $page) => $page
                ->loadDeferredProps(fn (Assert $page) => $page
                    ->has('contacts.data', 1)
                    ->where('contacts.data.0.firstName', 'Jane')
                )
            );

        // Potrivire pe email, nu doar pe nume.
        $this->actingAs($this->owner)->get('/marlin/contacts?filter[q]=smith@northwind')
            ->assertInertia(fn (Assert $page) => $page
                ->loadDeferredProps(fn (Assert $page) => $page->has('contacts.data', 1))
            );
    }

    public function test_the_account_filter_scopes_to_one_account(): void
    {
        [$firstAccountId, $secondAccountId] = TenantContext::run($this->marlin, function (): array {
            $first = $this->account('Northwind Industrial Supply LLC');
            $second = $this->account('Cascade Bearing Co.');
            $this->contact($first, 'Jane', 'Doe');
            $this->contact($second, 'John', 'Smith');

            return [$first->id, $second->id];
        });

        $this->actingAs($this->owner)->get("/marlin/contacts?filter[account]={$firstAccountId}")
            ->assertInertia(fn (Assert $page) => $page
                ->loadDeferredProps(fn (Assert $page) => $page
                    ->has('contacts.data', 1)
                    ->where('contacts.data.0.accountId', $firstAccountId)
                )
            );

        $this->assertNotEquals($firstAccountId, $secondAccountId);
    }

    public function test_a_second_page_is_reachable_by_cursor(): void
    {
        TenantContext::run($this->marlin, function (): void {
            $account = $this->account('Northwind Industrial Supply LLC');

            // PER_PAGE = 50 (App\Support\ListQuery) — 51 rânduri garantează o a doua pagină.
            for ($i = 0; $i < 51; $i++) {
                $this->contact($account, 'Jane', sprintf('Doe%02d', $i));
            }
        });

        // Referință propagată explicit prin closure-uri NUMITE (nu `fn`): o arrow
        // function capturează totul din scope-ul părinte PRIN VALOARE, inclusiv ce
        // ajunge mai departe într-un `use (&...)` imbricat — cursorul citit în
        // interior nu s-ar fi văzut niciodată în variabila de-aici.
        $nextCursor = null;

        $this->actingAs($this->owner)->get('/marlin/contacts')->assertInertia(function (Assert $page) use (&$nextCursor): void {
            $page->component('Contacts/Index')->loadDeferredProps(function (Assert $page) use (&$nextCursor): void {
                $page->has('contacts.data', 50)->where('contacts.nextCursor', function ($value) use (&$nextCursor): bool {
                    $nextCursor = $value;

                    return $value !== null;
                });
            });
        });

        $this->assertNotNull($nextCursor, 'Prima pagină ar trebui să aibă un cursor următor.');

        $this->actingAs($this->owner)->get('/marlin/contacts?cursor='.$nextCursor)
            ->assertInertia(fn (Assert $page) => $page
                ->loadDeferredProps(fn (Assert $page) => $page->has('contacts.data', 1))
            );
    }

    public function test_contacts_are_isolated_between_tenants_via_http(): void
    {
        $cascade = $this->makeTenant('cascade', 'Cascade Hydraulic Components');
        $this->makeMember($cascade, 'demo.owner@throughput.dev', Permissions::OWNER, user: $this->owner);

        TenantContext::run($this->marlin, function (): void {
            $this->contact($this->account('Northwind Industrial Supply LLC'), 'Jane', 'Doe');
        });

        TenantContext::run($cascade, function (): void {
            $this->contact($this->account('Cascade Bearing Co.'), 'John', 'Smith');
        });

        $this->actingAs($this->owner)->get('/marlin/contacts')
            ->assertInertia(fn (Assert $page) => $page
                ->loadDeferredProps(fn (Assert $page) => $page
                    ->has('contacts.data', 1)
                    ->where('contacts.data.0.firstName', 'Jane')
                )
            );

        $this->actingAs($this->owner)->get('/cascade/contacts')
            ->assertInertia(fn (Assert $page) => $page
                ->loadDeferredProps(fn (Assert $page) => $page
                    ->has('contacts.data', 1)
                    ->where('contacts.data.0.firstName', 'John')
                )
            );
    }

    private function account(string $name): Account
    {
        $account = new Account(['name' => $name]);
        $account->created_by = $this->owner->getKey();
        $account->save();

        return $account;
    }

    private function contact(Account $account, string $firstName, string $lastName, ?string $email = null): Contact
    {
        $contact = new Contact([
            'account_id' => $account->getKey(),
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $email,
        ]);
        $contact->created_by = $this->owner->getKey();
        $contact->save();

        return $contact;
    }
}
