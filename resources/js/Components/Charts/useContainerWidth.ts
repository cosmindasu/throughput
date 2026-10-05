import { useLayoutEffect, useRef, useState } from 'react';

/**
 * Lățimea unui container, urmărită cu `ResizeObserver`.
 *
 * Întoarce `0` până la PRIMA măsurătoare, iar apelantul trebuie să nu deseneze nimic de
 * lățimea aia. Varianta inițială întorcea o lățime de rezervă (640px) — o presupunere care
 * la lățime de telefon era de două ori containerul real: graficul se randa o dată la 640px,
 * depășea panoul (svg-ul e un element înlocuit, deci `min-w-0` pe părinți nu-l strânge) și
 * împingea DOCUMENTUL câteva sute de pixeli lateral pentru un cadru. Se vedea ca derulare
 * orizontală intermitentă pe mobil — `mobile-width.spec.ts` pica o dată la cinci rulări, cu
 * valori diferite de fiecare dată, fiindcă prindea sau nu cadrul acela.
 *
 * `useLayoutEffect`, nu `useEffect`: măsurarea se face ÎNAINTE de vopsire, deci trecerea de
 * la „nimic" la graficul desenat se întâmplă în același cadru și nu clipește.
 */
export function useContainerWidth<T extends HTMLElement>() {
    const ref = useRef<T>(null);
    const [width, setWidth] = useState(0);

    useLayoutEffect(() => {
        const node = ref.current;

        if (!node) {
            return;
        }

        // Prima măsurătoare sincron: `ResizeObserver` își livrează primul callback abia după
        // acest cadru, iar fără citirea de aici graficul ar lipsi exact un cadru.
        setWidth(Math.max(Math.round(node.getBoundingClientRect().width), 0));

        const observer = new ResizeObserver(([entry]) => {
            if (entry) {
                setWidth(Math.max(Math.round(entry.contentRect.width), 0));
            }
        });
        observer.observe(node);

        return () => observer.disconnect();
    }, []);

    return { ref, width };
}
