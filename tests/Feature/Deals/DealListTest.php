<?php

namespace Tests\Feature\Deals;

use App\Models\Account;
use App\Models\Deal;
use App\Models\Stage;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Support\Permissions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesPipelines;
use Tests\TestCase;

/**
 * `Deals/Index` — FR-PERF-03 (cursor, niciodată offset) + capcana (e) din task: `value`
 * e nullabil, iar `cursorPaginate()` compară strict pe coloana de sortare. O comparație
 * SQL cu NULL nu e niciodată adevărată, deci un cursor pe `value` direct ar face
 * deal-urile necalificate să dispară de la primul salt de pagină peste ele — nu doar să
 * le reordoneze. 51 de rânduri (peste plafonul de 50/pagină din `ListQuery::PER_PAGE`)
 * ca testul să treacă EXACT peste granița unde bug-ul s-ar manifesta.
 *
 * Notă tehnică: propul `deals` e deferred (Inertia 3), deci fiecare asertare citește
 * conținutul lui prin `AssertableInertia::reloadOnly()` — al doilea request, simulat
 * de pachetul de testare, exact ca în client. `reloadOnly()` cere un callback care
 * scrie într-o variabilă din scope-ul exterior: acel callback trebuie să fie o
 * closure OBIȘNUITĂ cu `use (&$var)` la FIECARE nivel de imbricare, nu un arrow
 * function (`fn`) — un `fn` capturează implicit prin VALOARE, deci o closure prin
 * REFERINȚĂ imbricată într-un `fn` scrie într-o copie locală a lui `fn`, nu în
 * variabila din metoda de test (capcană găsită direct aici, la scriere).
 */
class DealListTest extends TestCase
{
    use CreatesPipelines;

    private Tenant $tenant;

    private User $owner;

    private Account $account;

    private Stage $stage;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $this->owner = $this->makeMember($this->tenant, 'owner@throughput.dev', Permissions::OWNER);

        TenantContext::run($this->tenant, function (): void {
            $pipeline = $this->makeDefaultPipeline($this->tenant);
            $this->stage = $pipeline['stages']['New'];

            $account = new Account(['name' => 'Northwind Industrial Supply LLC']);
            $account->created_by = $this->owner->getKey();
            $account->save();
            $this->account = $account;
        });
    }

    public function test_sorting_by_value_descending_carries_null_valued_deals_across_the_cursor_boundary(): void
    {
        TenantContext::run($this->tenant, function (): void {
            $now = now();
            $rows = [];

            for ($i = 1; $i <= 49; $i++) {
                $rows[] = $this->rowFor(sprintf('Valued deal %02d', $i), (float) $i, $now);
            }

            // Amândouă NULL — 49 + 2 = 51, peste plafonul de 50/pagină: a doua pagină
            // conține exact UN rând, cel care ar fi „sărit" de un cursor construit pe
            // `value` direct.
            $rows[] = $this->rowFor('No value A', null, $now);
            $rows[] = $this->rowFor('No value B', null, $now);

            Deal::query()->insert($rows);
        });

        $this->clearDatabaseTenantContext();

        $firstPageTitles = $this->deferredDealTitlesFor('/marlin/deals?sort=-value', $nextCursor);

        $this->assertCount(50, $firstPageTitles);
        $this->assertNotNull($nextCursor, 'Ar trebui să mai rămână un rând pe a doua pagină.');

        // Primele 49, în ordine descrescătoare, sunt cele CU valoare.
        $this->assertSame(
            array_map(fn (int $i) => sprintf('Valued deal %02d', $i), range(49, 1)),
            array_slice($firstPageTitles, 0, 49)
        );

        $secondPageTitles = $this->deferredDealTitlesFor('/marlin/deals?sort=-value&cursor='.$nextCursor, $unusedCursor);

        $this->assertCount(1, $secondPageTitles, 'A doua pagină nu ar trebui să fie goală — capcana (e).');

        // Împreună, cele 51 de titluri există EXACT o dată — niciunul „sărit" de cursor.
        $allTitles = [...$firstPageTitles, ...$secondPageTitles];
        $this->assertCount(51, $allTitles);
        $this->assertContains('No value A', $allTitles);
        $this->assertContains('No value B', $allTitles);
    }

    /**
     * Aceeași capcană, pe cealaltă coloană sortabilă nullabilă. Crescător, Postgres pune
     * NULL-urile la final, deci granița de pagină cade pe un deal fără dată, iar cursorul
     * construit din el (`expected_close_date > NULL`) nu mai găsește niciun rând.
     */
    public function test_sorting_by_expected_close_date_carries_undated_deals_across_the_cursor_boundary(): void
    {
        TenantContext::run($this->tenant, function (): void {
            $now = now();
            $rows = [];

            for ($i = 1; $i <= 49; $i++) {
                $rows[] = $this->rowFor(sprintf('Dated deal %02d', $i), 1000.0, $now, $now->copy()->addDays($i)->toDateString());
            }

            $rows[] = $this->rowFor('No close date A', 1000.0, $now);
            $rows[] = $this->rowFor('No close date B', 1000.0, $now);

            Deal::query()->insert($rows);
        });

        $this->clearDatabaseTenantContext();

        $firstPageTitles = $this->deferredDealTitlesFor('/marlin/deals?sort=expected_close_date', $nextCursor);

        $this->assertCount(50, $firstPageTitles);
        $this->assertNotNull($nextCursor, 'Ar trebui să mai rămână un rând pe a doua pagină.');

        // Cea mai apropiată dată întâi; deal-urile fără dată vin după toate cele datate.
        $this->assertSame(
            array_map(fn (int $i) => sprintf('Dated deal %02d', $i), range(1, 49)),
            array_slice($firstPageTitles, 0, 49)
        );

        $secondPageTitles = $this->deferredDealTitlesFor('/marlin/deals?sort=expected_close_date&cursor='.$nextCursor, $unusedCursor);

        $this->assertCount(1, $secondPageTitles, 'Al doilea deal fără dată nu trebuie să dispară dintre pagini.');

        $allTitles = [...$firstPageTitles, ...$secondPageTitles];
        $this->assertContains('No close date A', $allTitles);
        $this->assertContains('No close date B', $allTitles);
    }

    public function test_filters_by_status_and_owner(): void
    {
        $agent = $this->makeMember($this->tenant, 'agent@throughput.dev', Permissions::AGENT);

        TenantContext::run($this->tenant, function () use ($agent): void {
            $this->dealNamed('Owned by owner', 1000, owner: $this->owner);
            $deal = $this->dealNamed('Owned by agent', 2000, owner: $agent);
            $deal->status = Deal::STATUS_WON;
            $deal->save();
        });

        $this->clearDatabaseTenantContext();

        $this->assertDealsListContains('/marlin/deals?filter[owner]=me', ['Owned by owner']);
        $this->assertDealsListContains('/marlin/deals?filter[owner]=all', ['Owned by owner', 'Owned by agent']);
        $this->assertDealsListContains('/marlin/deals?filter[status]=won', ['Owned by agent']);
    }

    public function test_an_agent_defaults_to_their_own_deals(): void
    {
        $agent = $this->makeMember($this->tenant, 'agent@throughput.dev', Permissions::AGENT);

        TenantContext::run($this->tenant, function () use ($agent): void {
            $this->dealNamed('Owned by owner', 1000, owner: $this->owner);
            $this->dealNamed('Owned by agent', 2000, owner: $agent);
        });

        $this->clearDatabaseTenantContext();

        $titles = null;

        $this->actingAs($agent)->get('/marlin/deals')
            ->assertInertia(function (Assert $page) use (&$titles): void {
                $page->where('filters.filter.owner', 'me');
                $page->reloadOnly('deals', function (Assert $reloaded) use (&$titles): void {
                    $titles = array_column($reloaded->toArray()['props']['deals']['data'], 'title');
                });
            });

        $this->assertSame(['Owned by agent'], $titles);
    }

    /**
     * @param  list<string>  $expected
     */
    private function assertDealsListContains(string $uri, array $expected): void
    {
        $titles = $this->deferredDealTitlesFor($uri, $unusedCursor);

        sort($expected);
        sort($titles);
        $this->assertSame($expected, $titles);
    }

    /**
     * Cere pagina, urmărește propul deferred `deals` (al doilea request, ca la client)
     * și întoarce titlurile rândurilor, plus `nextCursor` prin referință.
     *
     * @return list<string>
     */
    private function deferredDealTitlesFor(string $uri, ?string &$nextCursor): array
    {
        $titles = null;
        $cursor = null;

        $this->actingAs($this->owner)->get($uri)
            ->assertOk()
            ->assertInertia(function (Assert $page) use (&$titles, &$cursor): void {
                $page->reloadOnly('deals', function (Assert $reloaded) use (&$titles, &$cursor): void {
                    $deals = $reloaded->toArray()['props']['deals'];
                    $titles = array_column($deals['data'], 'title');
                    $cursor = $deals['nextCursor'];
                });
            });

        $nextCursor = $cursor;

        return $titles;
    }

    /**
     * @return array<string, mixed>
     */
    private function rowFor(string $title, ?float $value, Carbon $now, ?string $expectedCloseDate = null): array
    {
        return [
            'id' => (string) Str::ulid(),
            'tenant_id' => $this->tenant->getKey(),
            'account_id' => $this->account->getKey(),
            'pipeline_id' => $this->stage->pipeline_id,
            'stage_id' => $this->stage->getKey(),
            'owner_user_id' => $this->owner->getKey(),
            'title' => $title,
            'value' => $value,
            'expected_close_date' => $expectedCloseDate,
            'currency' => 'USD',
            'status' => Deal::STATUS_OPEN,
            'created_by' => $this->owner->getKey(),
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    private function dealNamed(string $title, ?float $value, ?User $owner = null): Deal
    {
        $owner ??= $this->owner;

        $deal = new Deal([
            'account_id' => $this->account->getKey(),
            'pipeline_id' => $this->stage->pipeline_id,
            'stage_id' => $this->stage->getKey(),
            'owner_user_id' => $owner->getKey(),
            'title' => $title,
            'value' => $value,
            'status' => Deal::STATUS_OPEN,
        ]);
        $deal->created_by = $owner->getKey();
        $deal->save();

        return $deal;
    }
}
