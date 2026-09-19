import type { ListState } from '@/types/generated';

type ApplyFn = (next: Partial<ListState> & { columns?: string[] }) => void;

interface UseListColumnsResult {
    toggle: (key: string) => void;
    moveUp: (key: string) => void;
    moveDown: (key: string) => void;
}

/**
 * Selectorul generic de coloane (specs.md §15.1) — bife + reordonare prin „Move up/down"
 * (WCAG 2.2 SC 2.5.7, fără drag ca singură cale). Scrie `?columns=` prin ACELAȘI `apply()`
 * pe care `useListFilters` îl folosește pentru filtre/sortare (parametrul `apply`, de obicei
 * cel întors de `useListFilters(list, columns)`), ca o schimbare de coloane să păstreze
 * filtrele/sortarea curente — o a doua navigare separată ar putea diverge de ele.
 *
 * `columns` e sursa de adevăr (props Inertia, validate server-side de
 * `App\Support\SavedViews\ListColumns`) — hook-ul nu ține stare locală, doar traduce
 * bifă/mutare într-o listă nouă și o trimite mai departe.
 */
export function useListColumns(columns: string[], apply: ApplyFn): UseListColumnsResult {
    const commit = (next: string[]) => apply({ columns: next });

    const toggle = (key: string) => {
        const isSelected = columns.includes(key);

        // Cel puțin o coloană rămâne vizibilă — altfel serverul revine tăcut la implicitul
        // resursei (`ListColumns::sanitize()`, listă goală → `defaultColumns()`), ceea ce ar
        // face „debifează ultima" un no-op confuz, nu o eroare vizibilă. Blocat aici, nu doar
        // vizual în `ColumnSelector` (`aria-disabled`): un apel direct al hook-ului rămâne sigur.
        if (isSelected && columns.length <= 1) {
            return;
        }

        commit(isSelected ? columns.filter((column) => column !== key) : [...columns, key]);
    };

    const moveUp = (key: string) => {
        const index = columns.indexOf(key);
        if (index <= 0) {
            return;
        }

        const next = [...columns];
        [next[index - 1], next[index]] = [next[index], next[index - 1]];
        commit(next);
    };

    const moveDown = (key: string) => {
        const index = columns.indexOf(key);
        if (index === -1 || index >= columns.length - 1) {
            return;
        }

        const next = [...columns];
        [next[index + 1], next[index]] = [next[index], next[index + 1]];
        commit(next);
    };

    return { toggle, moveUp, moveDown };
}
