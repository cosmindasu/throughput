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
 *
 * **Zeroul se scrie în PRIMUL CADRU, nu înainte de el.** Varianta inițială îl punea în DOM
 * imediat ce efectul rula, apoi aștepta `requestAnimationFrame` — iar într-un tab de fundal
 * browserul nu produce cadre, deci rAF nu se execută NICIODATĂ și cifra rămânea pe zero cât
 * timp pagina stătea ascunsă. Prins pe board-ul de pipeline: antetele coloanelor arătau
 * $15.848.875, iar banda de sinteză de deasupra lor, $0. Amânat la primul cadru, un tab
 * ascuns păstrează pur și simplu valoarea randată de React, iar când devine vizibil
 * animația pornește de acolo.
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
