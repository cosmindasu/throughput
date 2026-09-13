<?php

namespace Tests\Unit;

use App\Support\ListQuery;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * Plan §1.2 regula 6 — cum se citește URL-ul unei liste.
 *
 * Fără bază de date: interpretarea query string-ului e logică pură. Aplicarea pe SQL
 * (sortare cu departajare, cursor) se verifică în testele HTTP ale fiecărei liste.
 */
class ListQueryTest extends TestCase
{
    public function test_a_default_filter_applies_only_when_the_key_is_absent(): void
    {
        $this->assertSame('me', $this->parse('/accounts', ['owner' => 'me'])->filter('owner'));

        // `filter[owner]=` gol e o alegere explicită: fără filtru, deci fără implicit.
        $this->assertNull($this->parse('/accounts?filter[owner]=', ['owner' => 'me'])->filter('owner'));

        $this->assertSame('all', $this->parse('/accounts?filter[owner]=all', ['owner' => 'me'])->filter('owner'));
    }

    public function test_unknown_keys_and_rejected_values_are_ignored_not_rejected(): void
    {
        $list = ListQuery::fromRequest(
            Request::create('/accounts?filter[status]=bogus&filter[tenant_id]=01J0000000000000000000000'),
            ['status'],
            ['name'],
            'name',
            [],
            fn (string $key, string $value) => $value !== 'bogus',
        );

        $this->assertSame([], $list->filters);
    }

    public function test_an_unsortable_column_falls_back_to_the_default_sort(): void
    {
        $this->assertSame('name', $this->parse('/accounts?sort=-password')->sort);

        $list = $this->parse('/accounts?sort=-created_at');

        $this->assertSame('created_at', $list->sortColumn());
        $this->assertSame('desc', $list->sortDirection());
    }

    public function test_the_canonical_state_leaves_the_cursor_out(): void
    {
        $list = $this->parse('/accounts?filter[status]=active&sort=-created_at&cursor=abc');

        $this->assertSame('abc', $list->cursor);
        $this->assertSame(['filter' => ['status' => 'active'], 'sort' => '-created_at'], $list->toArray());
    }

    /**
     * §13.2 — reconstrucția din `bulk_operations.filter_snapshot`, folosită de exportul în
     * coadă. Aceleași reguli ca `fromRequest()`, fără cursor și fără `defaultFilters`
     * (starea salvată e deja rezultatul aplicării implicitului la momentul declanșării).
     */
    public function test_from_state_reapplies_the_same_validation_as_from_request(): void
    {
        $list = ListQuery::fromState(
            ['filter' => ['status' => 'active', 'tenant_id' => 'ignored'], 'sort' => '-created_at'],
            ['status', 'owner'],
            ['name', 'created_at'],
            'name',
        );

        $this->assertSame(['status' => 'active'], $list->filters);
        $this->assertSame('-created_at', $list->sort);
        $this->assertNull($list->cursor);
    }

    public function test_from_state_ignores_rejected_values_and_falls_back_on_bad_sort(): void
    {
        $list = ListQuery::fromState(
            ['filter' => ['status' => 'bogus'], 'sort' => 'password'],
            ['status'],
            ['name'],
            'name',
            fn (string $key, string $value) => $value !== 'bogus',
        );

        $this->assertSame([], $list->filters);
        $this->assertSame('name', $list->sort);
    }

    /**
     * @param  array<string, string>  $defaults
     */
    private function parse(string $uri, array $defaults = []): ListQuery
    {
        return ListQuery::fromRequest(Request::create($uri), ['status', 'owner'], ['name', 'created_at'], 'name', $defaults);
    }
}
