import { router, usePage } from '@inertiajs/react';
import type { ListState } from '@/types/generated';

/**
 * Schimbarea filtrelor/sortării unei liste, în forma de URL din specs.md §15.2
 * (`?filter[status]=active&sort=-created_at`).
 *
 * Cursorul se pierde deliberat la orice schimbare: o poziție adâncă dintr-un alt filtru
 * n-are sens în noul set. Starea rămâne în URL, nu în React, ca linkul să fie partajabil
 * și ca o vizualizare salvată să poată fi, pur și simplu, un URL.
 *
 * Eliminarea unei chei readuce implicitul serverului (ex: „My accounts" pentru Agent);
 * pentru „fără filtru" explicit, lista acceptă o valoare dedicată (`owner=all`).
 *
 * `columns` (opțional, specs.md §15.1) — coloanele EFECTIVE curente ale paginii, pe listele
 * cu selector. Transmise aici (nu doar în `useListColumns`) ca orice schimbare de
 * filtru/sortare să le PĂSTREZE în URL — altfel `router.get()` ar rescrie query string-ul
 * complet și `?columns=` ar dispărea la următorul clic pe „Sort by".
 */
export function useListFilters(state: ListState, columns?: string[]) {
    const { url } = usePage();
    const path = url.split('?')[0];

    const apply = (next: Partial<ListState> & { columns?: string[] }) => {
        const effectiveColumns = next.columns ?? columns;

        router.get(
            path,
            {
                filter: next.filter ?? state.filter,
                sort: next.sort ?? state.sort,
                ...(effectiveColumns ? { columns: effectiveColumns.join(',') } : {}),
            },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    const setFilter = (key: string, value: string | null) => {
        const filter = { ...state.filter };

        if (value === null || value === '') {
            delete filter[key];
        } else {
            filter[key] = value;
        }

        apply({ filter });
    };

    return { apply, setFilter, setSort: (sort: string) => apply({ sort }) };
}
