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
     * @param  array<string, string>  $defaults
     */
    private function parse(string $uri, array $defaults = []): ListQuery
    {
        return ListQuery::fromRequest(Request::create($uri), ['status', 'owner'], ['name', 'created_at'], 'name', $defaults);
    }
}
