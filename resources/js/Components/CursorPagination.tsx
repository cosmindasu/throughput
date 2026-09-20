import { usePage } from '@inertiajs/react';
import { ButtonLink, buttonClass } from '@/Components/Button';

interface CursorPaginationProps {
    nextCursor: string | null;
    prevCursor: string | null;
}

/**
 * FR-PERF-03 — paginare pe cursor, niciodată pe offset. Doar `cursor` se schimbă în
 * URL; filtrele și sortarea rămân, ca linkul paginii 7 să fie tot partajabil (§15.2).
 *
 * Fără „pagina X din Y": un cursor nu știe câte pagini sunt, iar un `count(*)` pe fiecare
 * navigare ar plăti exact costul pe care paginarea pe cursor îl evită.
 *
 * SC 4.1.3 (Status messages) — anunțul „Results updated." la schimbarea de pagină NU
 * trăiește aici. Această componentă e parte a componentei de PAGINĂ, care se remontează la
 * fiecare navigare GET (`Date.now()` ca `key`, vezi `.ai/rules/frontend.md`, „Pagina se
 * remontează la fiecare navigare") — orice `useState`/`useRef` local moare și renaște la
 * fiecare clic pe „Next"/"Previous", înainte ca un efect să apuce să vadă o schimbare reală.
 * Anunțul trăiește în `ListUpdateAnnouncer` (`resources/js/Components/ListUpdateAnnouncer.tsx`),
 * montat o singură dată în layout-ul PERSISTENT (`AppLayout`).
 */
export default function CursorPagination({ nextCursor, prevCursor }: CursorPaginationProps) {
    const { url } = usePage();

    if (!nextCursor && !prevCursor) {
        return null;
    }

    const hrefFor = (cursor: string) => {
        const target = new URL(url, 'http://localhost');
        target.searchParams.set('cursor', cursor);

        return `${target.pathname}${target.search}`;
    };

    const inert = `${buttonClass('secondary')} pointer-events-none opacity-60`;

    return (
        <nav aria-label="Pagination" className="flex items-center justify-end gap-2">
            {prevCursor ? (
                <ButtonLink href={hrefFor(prevCursor)} rel="prev">
                    Previous
                </ButtonLink>
            ) : (
                <span aria-disabled="true" className={inert}>
                    Previous
                </span>
            )}
            {nextCursor ? (
                <ButtonLink href={hrefFor(nextCursor)} rel="next">
                    Next
                </ButtonLink>
            ) : (
                <span aria-disabled="true" className={inert}>
                    Next
                </span>
            )}
        </nav>
    );
}
