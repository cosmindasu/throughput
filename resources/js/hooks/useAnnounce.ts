import { useCallback, useEffect, useRef, useState } from 'react';

/**
 * SC 4.1.3 (Status Messages) — un anunțător `aria-live` reutilizabil care garantează
 * tranziția REALĂ `'' → text` la fiecare apel, nu doar o intenție de a scrie.
 *
 * Extras din `Pipeline/Index.tsx` (P2-005) — `Deals/Kanban.tsx` (FE-03, audit frontend)
 * scria direct în regiunea `aria-live` (`setAnnouncement(text)`), fără acest tipar, și
 * repeta exact bug-ul deja găsit și reparat aici: React iese din `setState` la valoare
 * IDENTICĂ cu cea curentă (`objectIs`, în `dispatchSetStateInternal`) ÎNAINTE de a
 * programa randarea — fără randare nu există mutație de DOM, iar o regiune `aria-live`
 * anunță mutația, nu intenția. Două deal-uri mutate consecutiv spre aceeași etapă (sau,
 * aici, două „Move up”/„Move down” la rând cu același rezultat textual) ar produce astfel
 * același șir de două ori, iar a doua oară cititorul de ecran n-ar auzi nimic.
 *
 * Fix: golire SINCRONĂ, apoi textul pe frame-ul următor (`requestAnimationFrame`) — două
 * commit-uri distincte, niciodată contopite de React într-un singur pas care ar anula
 * tranziția. `cancelAnimationFrame` la fiecare apel nou ȘI la demontare, ca un anunț mai
 * vechi, încă neafișat, să nu apară după unul mai nou. Vezi `.ai/rules/frontend.md`, „O
 * regiune aria-live nu reacționează la setState, ci la mutația DOM-ului”.
 */
export function useAnnounce(): { announcement: string; announce: (text: string) => void } {
    const [announcement, setAnnouncement] = useState('');
    const frameRef = useRef<number | null>(null);

    useEffect(
        () => () => {
            if (frameRef.current !== null) {
                cancelAnimationFrame(frameRef.current);
            }
        },
        [],
    );

    const announce = useCallback((text: string) => {
        if (frameRef.current !== null) {
            cancelAnimationFrame(frameRef.current);
        }

        setAnnouncement('');
        frameRef.current = requestAnimationFrame(() => {
            frameRef.current = null;
            setAnnouncement(text);
        });
    }, []);

    return { announcement, announce };
}
