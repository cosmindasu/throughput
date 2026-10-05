import { useLayoutEffect, useRef } from 'react';

interface AnimatedNumberProps {
    value: number;
    format: (value: number) => string;
    durationMs?: number;
}

/**
 * Numărătoare către `value`, scrisă DIRECT în nodul de text (fără `useState`): nu produce
 * 40 de randări React, iar `react-hooks/set-state-in-effect` n-are ce să prindă. Nu se
 * folosește în regiuni `aria-live`.
 *
 * Trei lucruri pe care prima versiune le greșea, toate găsite la auditul valului:
 *
 * 1. **Pornea mereu de la ZERO, și la ACTUALIZĂRI.** Pe kanban, fiecare mutare de card
 *    schimbă totalul ponderat, deci placa sărea de la „$15,8M" la „$0" și urca 700 ms —
 *    utilizatorul citea, în drum, un total care nu există. Acum pleacă de la cifra efectiv
 *    AFIȘATĂ (`displayed`), nu de la zero; zeroul rămâne doar punctul de pornire al primei
 *    montări.
 *
 * 2. **Curățarea scria valoarea VECHE peste cea nouă.** `return () => { node.textContent =
 *    format(value) }` închidea peste `value`-ul efectului care se oprea, iar React scrisese
 *    deja textul cel nou la randare — deci într-un tab fără cadre DOM-ul rămânea pe cifra
 *    precedentă. Curățarea doar anulează cadrul; textul corect îl pune React.
 *
 * 3. **`progress` putea fi negativ.** Timestamp-ul primului cadru poate fi mai VECHI decât
 *    `performance.now()` citit într-un task lung (de pildă în timpul commit-ului React), iar
 *    `(1 - (1 - p) ** 4)` cu `p < 0` dă un factor negativ: un cadru cu „-$2.853.000".
 *
 * Valoarea finală e în markup de la randare (`{format(value)}`), deci un tab de fundal — unde
 * `requestAnimationFrame` nu se execută NICIODATĂ — arată cifra corectă, nu una intermediară.
 * Cu mișcare redusă nu se animă deloc.
 */
export default function AnimatedNumber({ value, format, durationMs = 700 }: AnimatedNumberProps) {
    const ref = useRef<HTMLSpanElement>(null);
    // Ce scrie ACUM în nod, nu ce ar trebui să scrie: o animație întreruptă la mijloc
    // continuă de unde se vedea, nu de unde ar fi trebuit să ajungă.
    const displayed = useRef(0);

    useLayoutEffect(() => {
        const node = ref.current;
        const from = displayed.current;

        if (!node || from === value || window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            displayed.current = value;

            return;
        }

        // Punctul de pornire se scrie AICI, în efectul de LAYOUT, deci ÎNAINTE de vopsire:
        // browserul nu apucă să deseneze niciodată valoarea finală, lată. Nu e estetică —
        // măsurat pe banda de sinteză a kanbanului, un singur cadru cu suma completă schimba
        // destul din așezarea paginii încât `mobile-width.spec.ts` să prindă, în jumătate din
        // rulări, derularea laterală pe care banda de coloane o propagă oricum spre document.
        node.textContent = format(from);

        const start = performance.now();
        let frame = 0;
        let started = false;

        // O țintă întreagă (un NUMĂR de comenzi, de afaceri) se numără în întregi: valorile
        // intermediare sunt fracționare, iar `formatNumber` arată trei zecimale implicit, deci
        // placa afișa „154,324" în drum spre „236". Formatele de bani n-au nevoie — ele
        // rotunjesc deja la unitate.
        const round = Number.isInteger(value);

        const tick = (now: number) => {
            started = true;
            const progress = Math.min(Math.max((now - start) / durationMs, 0), 1);
            const raw = from + (value - from) * (1 - (1 - progress) ** 4);
            const current = round ? Math.round(raw) : raw;

            displayed.current = current;
            node.textContent = format(current);

            if (progress < 1) {
                frame = requestAnimationFrame(tick);
            }
        };

        frame = requestAnimationFrame(tick);

        // Plasa pentru tabul de fundal: acolo `requestAnimationFrame` nu se execută NICIODATĂ
        // (browserul nu produce cadre), deci cifra ar rămâne înghețată pe punctul de pornire —
        // zero, la prima montare. Temporizatoarele însă rulează, doar încetinite, așa că dacă
        // după o secundă n-a venit niciun cadru, valoarea finală se scrie direct.
        const safety = window.setTimeout(() => {
            if (!started) {
                displayed.current = value;
                node.textContent = format(value);
            }
        }, 1_000);

        return () => {
            cancelAnimationFrame(frame);
            window.clearTimeout(safety);
        };
    }, [value, format, durationMs]);

    return <span ref={ref}>{format(value)}</span>;
}
