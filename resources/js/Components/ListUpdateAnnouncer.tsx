import { usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

/**
 * SC 4.1.3 (Status Messages) — anunță o schimbare de cursor pe ACEEAȘI listă (paginare),
 * pentru cititoarele de ecran.
 *
 * Trăiește ÎN LAYOUT-UL PERSISTENT (`AppLayout`), niciodată într-o componentă de pagină:
 * componenta de pagină se REMONTEAZĂ la fiecare navigare GET obișnuită — vezi
 * `.ai/rules/frontend.md`, „Pagina se remontează la fiecare navigare (Date.now())". O
 * primă versiune a acestui anunț trăia în `CursorPagination.tsx` (componentă de pagină) și
 * nu anunța NICIODATĂ nimic, exact din acest motiv: `useRef`/`useState` locale reporneau de
 * la zero la fiecare clic pe „Next"/"Previous", înainte ca efectul să apuce să vadă o
 * schimbare reală.
 *
 * `AppLayout` NU se remontează (doar copilul e cheiat cu `Date.now()`, nu wrapper-ul de
 * layout — verificat în sursă), deci acest component, montat o singură dată acolo, vede
 * fiecare navigare ca o simplă schimbare de `url` prin context (`usePage()`), nu ca un nou
 * montaj — sursa de adevăr corectă e `usePage().url`, nu starea unei componente de pagină.
 *
 * Anunță o schimbare a INTEROGĂRII pe ACELAȘI `pathname` — paginare, filtre, sortare — și
 * niciodată o navigare către altă pagină: un anunț la fiecare clic pe orice link din SPA ar
 * fi zgomot, nu semnal (aceeași regulă ca la `BulkSelectionBar`/`ColumnSelector`: o regiune
 * live anunță SCHIMBĂRI de stare, nu orice re-randare).
 *
 * Prima versiune compara DOAR `cursor` și rata astfel cazul cel mai frecvent: un filtru
 * aplicat de pe prima pagină nu schimbă `cursor` (era `null`, rămâne `null`), deși lista se
 * schimbă complet — deci exact utilizatorul care filtrează, nu paginează, nu afla nimic.
 * Găsit la auditul de accesibilitate al Fazei 5, pe `Invoices/Index`. Comparăm de-aceea tot
 * șirul de interogare; `useListFilters` navighează cu `preserveState: true`, deci componenta
 * de pagină nici nu se remontează, iar sursa de adevăr rămâne `usePage().url`.
 *
 * A DOUA capcană (găsită la review, verificată în sursă — nu presupusă): a doua paginare
 * consecutivă seta `announcement` la ACELAȘI text („Results updated.") ca prima, iar
 * `dispatchSetStateInternal` din `react-dom` (`objectIs(eagerState, currentState)`) face
 * bail-out ȘI RETURNEAZĂ ÎNAINTE de `scheduleUpdateOnFiber` când noua valoare e identică cu
 * cea curentă — nicio randare programată, deci DOM-ul regiunii live nu se atinge niciodată la
 * a doua/a treia navigare, iar o regiune `aria-live` reacționează la MUTAȚII de DOM, nu la
 * intenția de a scrie text. De-aia golim ÎNAINTE de anunț, pe frame-ul următor: garantează o
 * tranziție reală `'' → text` de fiecare dată, indiferent câte navigări identice se succed,
 * spre deosebire de „anunță, apoi golește după un timp", care ar rata un al doilea clic dat
 * înainte ca golirea întârziată să fi apucat să treacă prin DOM.
 */
export default function ListUpdateAnnouncer() {
    const { url } = usePage();
    const [announcement, setAnnouncement] = useState('');
    const previousRef = useRef<{ pathname: string; search: string } | null>(null);

    useEffect(() => {
        const [pathname, rawSearch] = url.split('?');
        const search = rawSearch ?? '';
        const previous = previousRef.current;
        previousRef.current = { pathname, search };

        // Fără `previous` (primul randaj al aplicației) — nimic de anunțat. Pathname diferit
        // — o navigare către altă resursă/pagină, nu o schimbare pe ACEEAȘI listă. Interogare
        // identică — o revizitare a aceleiași stări (ex. un `replace` care nu schimbă nimic).
        if (!previous || previous.pathname !== pathname || previous.search === search) {
            return;
        }

        // Golire sincronă (no-op dacă era deja '', ex. prima paginare din sesiune) + anunțul
        // REAL pe frame-ul următor: două commit-uri distincte, niciodată contopite de React
        // într-un singur pas care ar anula tranziția (motivul exact pentru care „golește apoi
        // scrie în ACELAȘI tick" nu ar funcționa aici).
        setAnnouncement('');
        const frame = requestAnimationFrame(() => setAnnouncement('Results updated.'));

        return () => cancelAnimationFrame(frame);
    }, [url]);

    return (
        <div role="status" aria-live="polite" aria-atomic="true" className="sr-only">
            {announcement}
        </div>
    );
}
