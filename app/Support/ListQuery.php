<?php

namespace App\Support;

use Closure;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\Cursor;

/**
 * Filtrele, sortarea și cursorul unei liste, citite din query string (plan §1.2 regula 6).
 *
 * Un singur value object, pentru că trei consumatori au nevoie de ACEEAȘI interpretare a
 * URL-ului: lista (FR-CRM-03), vizualizarea salvată care o serializează (§15) și exportul,
 * care trebuie să conțină exact rândurile de pe ecran (US-CRM-03). Trei parsări separate
 * ar produce, la prima divergență, un export cu alte rânduri decât lista.
 *
 * Forma URL-ului e cea din specs.md §15.2: `?filter[status]=active&sort=-created_at`.
 * Valorile invalide se IGNORĂ, nu dau 422: un link partajat cu un filtru învechit trebuie
 * să se deschidă, nu să arate o eroare de validare cuiva care n-a tastat nimic.
 */
final class ListQuery
{
    public const PER_PAGE = 50;

    /**
     * @param  array<string, string>  $filters
     */
    private function __construct(
        public readonly array $filters,
        public readonly string $sort,
        public readonly ?string $cursor,
    ) {}

    /**
     * @param  list<string>  $filterKeys
     * @param  list<string>  $sortableColumns  fără prefixul `-` al sortării descrescătoare
     * @param  array<string, string>  $defaultFilters  aplicate doar când cheia LIPSEȘTE din URL
     * @param  (Closure(string, string): bool)|null  $accepts  validarea unei perechi cheie/valoare
     */
    public static function fromRequest(
        Request $request,
        array $filterKeys,
        array $sortableColumns,
        string $defaultSort,
        array $defaultFilters = [],
        ?Closure $accepts = null,
    ): self {
        $raw = $request->query('filter');
        $raw = is_array($raw) ? $raw : [];

        $filters = [];

        foreach ($filterKeys as $key) {
            if (! array_key_exists($key, $raw)) {
                if (isset($defaultFilters[$key])) {
                    $filters[$key] = $defaultFilters[$key];
                }

                continue;
            }

            // Cheie prezentă, dar goală: alegere explicită de „fără filtru", deci nici
            // implicitul nu se mai aplică.
            $value = is_string($raw[$key]) ? mb_substr(trim($raw[$key]), 0, 100) : '';

            if ($value !== '' && ($accepts === null || $accepts($key, $value))) {
                $filters[$key] = $value;
            }
        }

        $sort = $request->query('sort');
        $sort = is_string($sort) && in_array(ltrim($sort, '-'), $sortableColumns, true) ? $sort : $defaultSort;

        $cursor = $request->query('cursor');

        return new self($filters, $sort, is_string($cursor) && $cursor !== '' ? $cursor : null);
    }

    /**
     * Reface un `ListQuery` dintr-o stare deja canonică (`toArray()`), nu dintr-un
     * request HTTP — necesar exportului în coadă (§13.2), care reia interogarea din
     * `bulk_operations.filter_snapshot`, într-un job, fără cerere.
     *
     * Aceleași reguli de validare ca `fromRequest()` (valorile necunoscute/respinse se
     * ignoră, nu aruncă): un snapshot vechi, scris înainte de o schimbare de filtre
     * acceptate, tot trebuie să producă un export, nu o eroare de job eșuat.
     *
     * Fără `$defaultFilters`: starea salvată e deja rezultatul aplicării implicitului
     * (ex: „My accounts" pentru Agent) de la momentul declanșării — reaplicarea lui aici
     * ar fi un al doilea loc care poate diverge de `fromRequest()`.
     *
     * @param  array{filter?: array<string, mixed>, sort?: mixed}  $state
     * @param  list<string>  $filterKeys
     * @param  list<string>  $sortableColumns
     * @param  (Closure(string, string): bool)|null  $accepts
     */
    public static function fromState(
        array $state,
        array $filterKeys,
        array $sortableColumns,
        string $defaultSort,
        ?Closure $accepts = null,
    ): self {
        $raw = $state['filter'] ?? [];
        $raw = is_array($raw) ? $raw : [];

        $filters = [];

        foreach ($filterKeys as $key) {
            if (! array_key_exists($key, $raw)) {
                continue;
            }

            $value = is_string($raw[$key]) ? mb_substr(trim($raw[$key]), 0, 100) : '';

            if ($value !== '' && ($accepts === null || $accepts($key, $value))) {
                $filters[$key] = $value;
            }
        }

        $sort = $state['sort'] ?? null;
        $sort = is_string($sort) && in_array(ltrim($sort, '-'), $sortableColumns, true) ? $sort : $defaultSort;

        return new self($filters, $sort, null);
    }

    public function filter(string $key): ?string
    {
        return $this->filters[$key] ?? null;
    }

    public function sortColumn(): string
    {
        return ltrim($this->sort, '-');
    }

    public function sortDirection(): string
    {
        return str_starts_with($this->sort, '-') ? 'desc' : 'asc';
    }

    /**
     * Sortarea cerută, plus cheia primară ca departajare: paginarea pe cursor are nevoie de
     * o ordine TOTALĂ. Doar pe `name`, două conturi cu același nume ar putea apărea pe
     * ambele pagini sau pe niciuna.
     */
    public function applySort(Builder $query): Builder
    {
        $direction = $this->sortDirection();

        return $query
            ->orderBy($this->sortColumn(), $direction)
            ->orderBy($query->getModel()->getKeyName(), $direction);
    }

    /**
     * FR-PERF-03: cursor, niciodată offset. Un cursor nevalid (link vechi, valoare tastată)
     * întoarce prima pagină — `Cursor::fromEncoded()` dă `null` pe orice nu decodează.
     */
    public function paginate(Builder $query): CursorPaginator
    {
        return $query->cursorPaginate(self::PER_PAGE, ['*'], 'cursor', Cursor::fromEncoded($this->cursor));
    }

    /**
     * Starea canonică, fără cursor: ce poartă linkurile de filtrare și ce serializează o
     * vizualizare salvată.
     *
     * @return array{filter: array<string, string>, sort: string}
     */
    public function toArray(): array
    {
        return ['filter' => $this->filters, 'sort' => $this->sort];
    }
}
