<?php

namespace App\Support\Exports;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Paginare pe CURSOR (keyset), pentru exporturi mari — găsit de scenariile k6:
 * `Builder::cursor()` NU apelează `eagerLoadRelations()` (spre deosebire de `get()`), deci
 * `->with(['account:id,name', 'owner:id,name'])` din `OrderList::baseQuery()` era ignorat
 * tăcut, iar `exportRow()` declanșa o interogare lazy per rând, per relație — măsurat,
 * 5.817 interogări pentru 2.908 rânduri (1 + 2×2.908), în loc de ~18 (vezi mai jos).
 *
 * NU `chunkById()` (tiparul din `PlanBulkOperationJob`): acolo ordinea rândurilor nu
 * contează — planificatorul doar chunk-uiește id-uri pentru un `UPDATE`, deci
 * `reorder()->orderBy($keyName)` (sortare forțată pe cheia primară) e sigur. Un export
 * trebuie să păstreze EXACT sortarea cerută de utilizator (`?sort=-created_at`,
 * `?sort=grand_total` etc., orice coloană, nu doar `id`) — `chunkById()` combinat cu o
 * sortare pe altă coloană decât cursorul lui ar sări sau ar dubla rânduri între chunk-uri
 * (cursorul filtrează pe `id`, dar ordinea reală o dă altă coloană, necorelată).
 *
 * De aceea, aici: `Builder::cursorPaginate()`, EXACT mecanismul deja folosit de listă
 * (`App\Support\ListQuery::paginate()`), care respectă orice `ORDER BY` deja pus pe
 * interogare — `ListQuery::applySort()` adaugă deja cheia primară ca tiebreaker, deci
 * paginarea rămâne corectă (fără rânduri sărite/duplicate) chiar și pe o coloană cu
 * valori egale (ex: mai multe comenzi cu același `grand_total`). Fiecare pagină cheamă
 * `Builder::get()` intern, care APLICĂ eager-load-ul — de-asta „per chunk", nu per rând.
 *
 * `clone $query` la fiecare pagină: `cursorPaginate()` mută starea interogării (adaugă
 * condiții de cursor, `LIMIT`) — reluarea pe interogarea ORIGINALĂ, nemodificată, e ce
 * face posibilă cererea „tot ce vine după cursorul X" la pasul următor.
 */
final class ExportQueryChunker
{
    private const CHUNK_SIZE = 500;

    /**
     * @param  callable(Collection):void  $callback
     */
    public static function each(Builder $query, callable $callback, int $chunkSize = self::CHUNK_SIZE): void
    {
        $cursor = null;

        do {
            $page = (clone $query)->cursorPaginate($chunkSize, ['*'], 'cursor', $cursor);

            $callback($page->getCollection());

            $cursor = $page->nextCursor();
        } while ($cursor !== null);
    }
}
