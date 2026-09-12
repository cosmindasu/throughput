<?php

namespace App\Support\Lists;

use App\Models\User;
use App\Support\ListQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Definiția unei liste: ce filtre acceptă, după ce se poate sorta și cum se traduc filtrele
 * în SQL.
 *
 * Lista web, exportul și vizualizările salvate trec toate prin `query()`, deci văd aceleași
 * rânduri pentru același URL. Izolarea de tenant NU e treaba acestei clase: global scope-ul
 * și RLS se aplică deja pe `baseQuery()`, iar un `where tenant_id` scris aici ar fi un al
 * treilea strat, care poate greși independent de celelalte două.
 */
abstract class ResourceList
{
    /** @return list<string> */
    abstract protected function filterKeys(): array;

    /** @return list<string> */
    abstract protected function sortableColumns(): array;

    abstract protected function defaultSort(): string;

    abstract protected function baseQuery(): Builder;

    abstract protected function applyFilters(Builder $query, ListQuery $list, User $user): void;

    /**
     * @return array<string, string>
     */
    protected function defaultFilters(User $user): array
    {
        return [];
    }

    protected function accepts(string $key, string $value): bool
    {
        return true;
    }

    public function parse(Request $request): ListQuery
    {
        return ListQuery::fromRequest(
            $request,
            $this->filterKeys(),
            $this->sortableColumns(),
            $this->defaultSort(),
            $this->defaultFilters($request->user()),
            $this->accepts(...),
        );
    }

    public function query(ListQuery $list, User $user): Builder
    {
        $query = $this->baseQuery();

        $this->applyFilters($query, $list, $user);

        return $list->applySort($query);
    }
}
