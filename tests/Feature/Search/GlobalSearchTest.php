<?php

namespace Tests\Feature\Search;

use App\Models\Account;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Pipeline;
use App\Models\Product;
use App\Models\Stage;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Support\Permissions;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * FR-SEARCH-01/02, BR-SEARCH-01 (specs.md §15.5) — căutarea globală (Cmd+K), verificată prin
 * cererea HTTP reală (`GET /{workspace}/search`): `auth → SetSessionContext → ResolveWorkspace`,
 * global scope, RLS și permisiuni per tenant sunt exact cele din producție, nu o aproximare la
 * nivel de serviciu.
 */
class GlobalSearchTest extends TestCase
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

    public function test_a_misspelled_term_still_finds_the_record_via_trigram_similarity(): void
    {
        // Nume scurt, deliberat: `similarity()` pe stringul ÎNTREG scade cu lungimea lui —
        // măsurat direct (`similarity('Fasteners Inc', 'fastners')` = 0.4375, peste pragul
        // implicit 0.3; un nume mult mai lung cu același cuvânt poate cădea sub prag).
        $this->createAccount($this->marlin, 'Fasteners Inc');
        $this->clearDatabaseTenantContext();

        // „fastners" — greșeală de tastare deliberată, exemplul din instrucțiunile pachetului.
        $response = $this->actingAs($this->owner)->getJson('/marlin/search?q=fastners');

        $response->assertOk();
        $this->assertSame(['Fasteners Inc'], $this->groupLabels($response, 'accounts'));
    }

    public function test_results_are_grouped_by_entity_type_with_a_visible_label(): void
    {
        $account = $this->createAccount($this->marlin, 'Northgate Supply Co.');
        $this->createContact($this->marlin, $account, 'Northgate', 'Buyer');
        $this->createDeal($this->marlin, $account, 'Northgate annual contract');
        $this->clearDatabaseTenantContext();

        $response = $this->actingAs($this->owner)->getJson('/marlin/search?q=northgate');

        $response->assertOk();
        $types = $this->groupTypes($response);

        $this->assertContains('accounts', $types);
        $this->assertContains('contacts', $types);
        $this->assertContains('deals', $types);
    }

    public function test_at_most_five_results_per_group(): void
    {
        foreach (range(1, 7) as $i) {
            $this->createAccount($this->marlin, "Redwood Fasteners #{$i}");
        }
        $this->clearDatabaseTenantContext();

        $response = $this->actingAs($this->owner)->getJson('/marlin/search?q=redwood');

        $response->assertOk();
        $this->assertCount(5, $this->groupLabels($response, 'accounts'));
    }

    /**
     * Deviație semnalată în raport: produsele n-au ecrane în Faza 2 (plan §9), deci un
     * grup „Products" ar duce la un link mort. Indexul GIN pe `products.name` EXISTĂ deja
     * (pregătire Faza 3), dar `SearchController` nu îl expune încă.
     */
    public function test_products_are_excluded_because_there_is_no_product_screen_yet(): void
    {
        TenantContext::run($this->marlin, function (): void {
            Product::query()->create(['name' => 'Unique Hex Bolt Widget']);
        });
        $this->clearDatabaseTenantContext();

        $response = $this->actingAs($this->owner)->getJson('/marlin/search?q=widget');

        $response->assertOk();
        $this->assertNotContains('products', $this->groupTypes($response));
    }

    public function test_a_term_matching_only_another_tenants_account_returns_nothing(): void
    {
        $this->createAccount($this->cascade, 'Unmistakable Cascade Only Account');
        $this->clearDatabaseTenantContext();

        $response = $this->actingAs($this->owner)->getJson('/marlin/search?q=unmistakable');

        $response->assertOk();

        // Grupul „actions" e independent de datele căutate (permisiune, nu rezultat de
        // navigare) — poate apărea legitim și fără nicio potrivire. Ce contează pentru
        // izolare e că NICIUN grup de date (accounts/contacts/deals) nu scapă din Cascade.
        $this->assertEmpty(array_intersect(['accounts', 'contacts', 'deals'], $this->groupTypes($response)));
    }

    public function test_viewer_never_sees_the_actions_group(): void
    {
        $this->createAccount($this->marlin, 'Viewer Visible Account');
        $viewer = $this->makeMember($this->marlin, 'demo.viewer@throughput.dev', Permissions::VIEWER);
        $this->clearDatabaseTenantContext();

        $response = $this->actingAs($viewer)->getJson('/marlin/search?q=viewer');

        $response->assertOk();
        $types = $this->groupTypes($response);

        $this->assertContains('accounts', $types, 'Viewer are drept de citire pe conturi (§7.4).');
        $this->assertNotContains('actions', $types, 'Viewer nu are nicio permisiune de creare.');
    }

    public function test_agent_sees_every_account_in_the_tenant_not_just_its_own(): void
    {
        $agent = $this->makeMember($this->marlin, 'demo.agent@throughput.dev', Permissions::AGENT);
        $this->createAccount($this->marlin, 'Agent Owned Account', ownerId: $agent->getKey());
        $this->createAccount($this->marlin, 'Someone Elses Account', ownerId: $this->owner->getKey());
        $this->clearDatabaseTenantContext();

        $response = $this->actingAs($agent)->getJson('/marlin/search?q=account');

        $response->assertOk();
        $labels = $this->groupLabels($response, 'accounts');

        $this->assertContains('Agent Owned Account', $labels);
        $this->assertContains('Someone Elses Account', $labels, '`accounts.view` e per tenant — §7.5 îngustează doar mutațiile, nu citirea.');
    }

    public function test_a_role_without_the_view_permission_never_receives_that_group(): void
    {
        $account = $this->createAccount($this->marlin, 'Visible Account');
        $this->createDeal($this->marlin, $account, 'Visible Deal');

        $viewer = $this->makeMember($this->marlin, 'demo.viewer@throughput.dev', Permissions::VIEWER);

        TenantContext::run($this->marlin, function () use ($viewer): void {
            app(PermissionRegistrar::class)->setPermissionsTeamId($this->marlin->getKey());
            Role::findByName(Permissions::VIEWER, 'web')->revokePermissionTo('deals.view');
            $viewer->forgetCachedPermissions();
        });
        $this->clearDatabaseTenantContext();

        $response = $this->actingAs($viewer)->getJson('/marlin/search?q=visible');

        $response->assertOk();
        $types = $this->groupTypes($response);

        $this->assertContains('accounts', $types);
        $this->assertNotContains('deals', $types, 'Rolul nu mai are `deals.view` — grupul lipsește, nu apare gol.');
    }

    public function test_query_shorter_than_two_characters_returns_the_initial_state_not_a_search(): void
    {
        $this->createAccount($this->marlin, 'A Very Findable Account');
        $this->clearDatabaseTenantContext();

        $response = $this->actingAs($this->owner)->getJson('/marlin/search?q=a');

        $response->assertOk()->assertJson(['query' => '']);
        $this->assertNotContains('accounts', $this->groupTypes($response), 'Sub 2 caractere = stare inițială, nu căutare (FR-SEARCH-01).');
    }

    public function test_initial_state_offers_recently_viewed_and_frequent_actions_never_a_blank_screen(): void
    {
        $account = $this->createAccount($this->marlin, 'Recently Opened Account');
        $this->clearDatabaseTenantContext();

        // `actingAs()` golește sesiunea (Tests\TestCase — capcana documentată în
        // .ai/rules/tenancy.md), deci sesiunea se populează DUPĂ, nu înainte.
        $this->actingAs($this->owner);
        $this->withSession([
            'recently_viewed.'.$this->marlin->getKey() => [[
                'type' => 'account',
                'id' => $account->getKey(),
                'label' => 'Recently Opened Account',
                'url' => "/marlin/accounts/{$account->getKey()}",
            ]],
        ]);

        $response = $this->getJson('/marlin/search');

        $response->assertOk();
        $recent = $this->group($response, 'recent');
        $actions = $this->group($response, 'actions');

        $this->assertNotNull($recent, 'Starea inițială trebuie să arate recent accesatele (FR-SEARCH-01).');
        $this->assertSame('Recently Opened Account', $recent['results'][0]['label']);
        $this->assertNotNull($actions, 'Owner are drept de creare — acțiunile frecvente trebuie prezente.');
    }

    public function test_recently_viewed_does_not_leak_between_workspaces(): void
    {
        $this->makeMember($this->cascade, 'demo.owner@throughput.dev', Permissions::OWNER, user: $this->owner);
        $this->clearDatabaseTenantContext();

        $this->actingAs($this->owner);
        $this->withSession([
            'recently_viewed.'.$this->marlin->getKey() => [[
                'type' => 'account', 'id' => 'x', 'label' => 'Marlin Only Item', 'url' => '/marlin/accounts/x',
            ]],
            'recently_viewed.'.$this->cascade->getKey() => [[
                'type' => 'account', 'id' => 'y', 'label' => 'Cascade Only Item', 'url' => '/cascade/accounts/y',
            ]],
        ]);

        $marlinRecent = $this->group($this->getJson('/marlin/search'), 'recent');
        $cascadeRecent = $this->group($this->getJson('/cascade/search'), 'recent');

        $this->assertSame('Marlin Only Item', $marlinRecent['results'][0]['label']);
        $this->assertSame('Cascade Only Item', $cascadeRecent['results'][0]['label']);
    }

    /**
     * P2-003 (code review): fostul test verifica doar `assertOk()` — o interogare care
     * ignora `%`/`_` ca metacaractere LIKE (bug-ul pe care `likePattern()` îl previne) tot
     * ar fi dat 200, doar cu rezultate greșite. Aici verificăm rezultatul, nu doar statusul:
     * un `%` TASTAT DE UTILIZATOR trebuie citit literal (potrivire pe textul „50%"), nu ca
     * wildcard SQL.
     */
    public function test_a_literal_percent_in_the_query_finds_the_account_containing_it(): void
    {
        $this->createAccount($this->marlin, 'Save 50% Now');
        $this->createAccount($this->marlin, 'Regular Price Supplies');
        $this->clearDatabaseTenantContext();

        $response = $this->actingAs($this->owner)->getJson('/marlin/search?q='.urlencode('50%'));

        $response->assertOk();
        $this->assertSame(['Save 50% Now'], $this->groupLabels($response, 'accounts'));
    }

    /**
     * P2-003 (code review): un termen făcut DOAR din metacaractere LIKE (`%`/`_`, fără
     * escapare ar potrivi orice rând) nu trebuie să se comporte ca un wildcard care
     * întoarce tot tenantul — `likePattern()` le escapează, deci termenul e citit literal
     * și nu se potrivește cu nume care nu conțin chiar acele caractere.
     */
    public function test_a_query_of_only_like_metacharacters_does_not_return_the_whole_tenant(): void
    {
        foreach (range(1, 6) as $i) {
            $this->createAccount($this->marlin, "Unrelated Account {$i}");
        }
        $this->clearDatabaseTenantContext();

        $response = $this->actingAs($this->owner)->getJson('/marlin/search?q='.urlencode('%%%'));

        $response->assertOk();
        $this->assertSame(
            [],
            $this->groupLabels($response, 'accounts'),
            'Un termen din doar `%` nu trebuie să se comporte ca un wildcard care întoarce tot tenantul.'
        );
    }

    public function test_a_query_longer_than_the_maximum_is_truncated_not_rejected(): void
    {
        $this->clearDatabaseTenantContext();

        $response = $this->actingAs($this->owner)->getJson('/marlin/search?q='.str_repeat('a', 500));

        $response->assertOk();
        $this->assertSame(100, mb_strlen((string) $response->json('query')));
    }

    /**
     * P3 (code review): `?q[]=x` fait `$request->query('q')` să întoarcă un array —
     * `(string) $array` (și, la fel, `Illuminate\Support\Stringable`) dă „Array to string
     * conversion". `SearchController` tratează explicit acest caz cu `is_string`, deci
     * termenul devine gol (sub `MIN_QUERY_LENGTH`) în loc să arunce.
     */
    public function test_an_array_query_parameter_does_not_crash_the_search(): void
    {
        $this->clearDatabaseTenantContext();

        $response = $this->actingAs($this->owner)->getJson('/marlin/search?q[]=x&q[]=y');

        $response->assertOk()->assertJson(['query' => '']);
    }

    /**
     * P3 (code review): `GET /{workspace}/search` e apelat la fiecare tastă (debounced),
     * deci are nevoie de o limită — generoasă (120/minut), ca să nu încetinească tastarea
     * normală, dar prezentă împotriva unui client rupt sau al unui script automat.
     */
    public function test_search_requests_are_rate_limited_per_user(): void
    {
        $this->clearDatabaseTenantContext();
        $this->actingAs($this->owner);

        foreach (range(1, 120) as $_) {
            $this->getJson('/marlin/search?q=rate')->assertOk();
        }

        $this->getJson('/marlin/search?q=rate')->assertStatus(429);
    }

    private function createAccount(Tenant $tenant, string $name, ?string $ownerId = null): Account
    {
        return TenantContext::run($tenant, function () use ($name, $ownerId): Account {
            $account = new Account(['name' => $name, 'status' => Account::STATUS_ACTIVE, 'owner_user_id' => $ownerId]);
            $account->created_by = $this->owner->getKey();
            $account->save();

            return $account;
        });
    }

    private function createContact(Tenant $tenant, Account $account, string $lastName, string $title): Contact
    {
        return TenantContext::run($tenant, function () use ($account, $lastName, $title): Contact {
            $contact = new Contact([
                'account_id' => $account->getKey(),
                'first_name' => 'Pat',
                'last_name' => $lastName,
                'title' => $title,
            ]);
            $contact->created_by = $this->owner->getKey();
            $contact->save();

            return $contact;
        });
    }

    private function createDeal(Tenant $tenant, Account $account, string $title): Deal
    {
        return TenantContext::run($tenant, function () use ($account, $title): Deal {
            $pipeline = Pipeline::query()->create(['name' => 'Standard']);
            $stage = Stage::query()->create(['pipeline_id' => $pipeline->getKey(), 'name' => 'Qualification', 'position' => 1]);

            $deal = new Deal([
                'account_id' => $account->getKey(),
                'pipeline_id' => $pipeline->getKey(),
                'stage_id' => $stage->getKey(),
                'owner_user_id' => $this->owner->getKey(),
                'title' => $title,
                'status' => Deal::STATUS_OPEN,
            ]);
            $deal->created_by = $this->owner->getKey();
            $deal->save();

            return $deal;
        });
    }

    /**
     * @return list<string>
     */
    private function groupTypes(TestResponse $response): array
    {
        return collect($response->json('groups'))->pluck('type')->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function group(TestResponse $response, string $type): ?array
    {
        return collect($response->json('groups'))->firstWhere('type', $type);
    }

    /**
     * @return list<string>
     */
    private function groupLabels(TestResponse $response, string $type): array
    {
        $group = $this->group($response, $type);

        return $group === null ? [] : collect($group['results'])->pluck('label')->all();
    }
}
