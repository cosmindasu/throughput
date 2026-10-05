import { useEffect, useRef, useState } from 'react';

/**
 * Lățimea unui container, urmărită cu ResizeObserver. Fără SSR în proiect, deci nu există
 * risc de desincronizare la hidratare; `fallback` e doar prima randare, înainte de măsurare.
 */
export function useContainerWidth<T extends HTMLElement>(fallback = 640) {
    const ref = useRef<T>(null);
    const [width, setWidth] = useState(fallback);

    useEffect(() => {
        const node = ref.current;

        if (!node) {
            return;
        }

        const observer = new ResizeObserver(([entry]) => {
            if (entry) {
                setWidth(Math.max(Math.round(entry.contentRect.width), 240));
            }
        });
        observer.observe(node);

        return () => observer.disconnect();
    }, []);

    return { ref, width };
}
