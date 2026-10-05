import { useLayoutEffect, useRef } from 'react';

interface AnimatedNumberProps {
    value: number;
    format: (value: number) => string;
    durationMs?: number;
}

/**
 * Numărătoare de la 0 la `value`, scrisă DIRECT în nodul de text (fără `useState`): nu produce
 * 40 de randări React, iar `react-hooks/set-state-in-effect` n-are ce să prindă. Valoarea FINALĂ
 * e mereu în markup (și rămâne singura când mișcarea e redusă) — cititorul de ecran și testele
 * `getByText` văd cifra reală, nu un cadru intermediar. Nu se folosește în regiuni `aria-live`.
 */
export default function AnimatedNumber({ value, format, durationMs = 700 }: AnimatedNumberProps) {
    const ref = useRef<HTMLSpanElement>(null);

    useLayoutEffect(() => {
        const node = ref.current;

        if (!node || window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            return;
        }

        const start = performance.now();
        let frame = 0;

        node.textContent = format(0);

        const tick = (now: number) => {
            const progress = Math.min((now - start) / durationMs, 1);
            node.textContent = format(value * (1 - (1 - progress) ** 4));

            if (progress < 1) {
                frame = requestAnimationFrame(tick);
            }
        };

        frame = requestAnimationFrame(tick);

        return () => {
            cancelAnimationFrame(frame);
            node.textContent = format(value);
        };
    }, [value, format, durationMs]);

    return <span ref={ref}>{format(value)}</span>;
}
