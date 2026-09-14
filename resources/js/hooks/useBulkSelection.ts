import { useMemo, useState } from 'react';

/**
 * §13.1 — pattern „Select all matching filter": checkbox de HEADER selectează doar rândurile
 * paginii curente (`pageIds`); un link separat, afișat DOAR după ce header-ul e bifat,
 * comută pe modul „tot filtrul" (`matchingFilter`) — niciodată implicit unul pentru celălalt.
 *
 * Selecția trăiește în React, NU în URL: spre deosebire de filtre/sort (§15.2, partajabile),
 * o selecție n-are sens păstrată la reîncărcare sau trimisă altcuiva.
 */
export function useBulkSelection(pageIds: string[]) {
    const [selected, setSelected] = useState<Set<string>>(new Set());
    const [matchingFilter, setMatchingFilter] = useState(false);

    const allOnPageSelected = pageIds.length > 0 && pageIds.every((id) => selected.has(id));

    const toggleRow = (id: string) => {
        setSelected((previous) => {
            // Ieșirea din modul „tot filtrul" printr-o schimbare manuală pornește de la o
            // selecție goală — nu de la „tot, minus un rând", pattern pe care specs.md §13.1
            // nu-l descrie (all-or-nothing pe modul „matching filter").
            const base = matchingFilter ? new Set<string>() : previous;
            const next = new Set(base);

            if (next.has(id)) {
                next.delete(id);
            } else {
                next.add(id);
            }

            return next;
        });
        setMatchingFilter(false);
    };

    const toggleAllOnPage = () => {
        setSelected((previous) => {
            const base = matchingFilter ? new Set<string>() : previous;
            const next = new Set(base);

            if (allOnPageSelected && !matchingFilter) {
                pageIds.forEach((id) => next.delete(id));
            } else {
                pageIds.forEach((id) => next.add(id));
            }

            return next;
        });
        setMatchingFilter(false);
    };

    const selectAllMatching = () => setMatchingFilter(true);

    const clear = () => {
        setMatchingFilter(false);
        setSelected(new Set());
    };

    const isSelected = (id: string) => matchingFilter || selected.has(id);

    // P1-002 (code review) — DOAR bifele explicite de pe pagina curentă (≤ mărimea
    // paginii), NICIODATĂ N-ul din modul „select all matching filter": acest hook n-are
    // vizibilitate pe `total`-ul paginii (trăiește în props, nu aici). Numărul EFECTIV
    // afectat de operație, folosit la afișare/dialog/prag, se calculează în
    // `BulkSelectionBar` (`matchingFilter ? total : selectedCount`), nu aici.
    const selectedCount = useMemo(() => selected.size, [selected]);

    return {
        matchingFilter,
        allOnPageSelected: allOnPageSelected || matchingFilter,
        selectedCount,
        selectedIds: Array.from(selected),
        isSelected,
        toggleRow,
        toggleAllOnPage,
        selectAllMatching,
        clear,
    };
}
