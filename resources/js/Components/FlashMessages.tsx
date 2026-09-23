import { usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

type Flash = {
    success: string | null;
    error: string | null;
    notice: string | null;
};

const EMPTY: Flash = { success: null, error: null, notice: null };

/**
 * Mesajele `flash.success` / `flash.error` / `flash.notice` din props comune
 * (HandleInertiaRequests). `role="status"` pentru confirmări și notificări discrete,
 * `role="alert"` pentru erori: un refuz de server (ex: „Set a deal value before marking
 * as Won") trebuie anunțat, nu doar colorat. `notice` (FR-VIEW-02 — vederea Team folosită
 * ca implicit a fost ștearsă) e tonul `info`, nu `danger`: nu e o greșeală a cui o vede.
 *
 * SC 4.1.3 — DOUĂ condiții, ambele necesare; oricare singură nu produce niciun anunț.
 * Găsite la auditul de accesibilitate al Fazei 5, pe un mesaj repetat („Carrier settings
 * updated." la fiecare comutare de furnizor). Regula generală e în `.ai/rules/frontend.md`.
 *
 * 1. **Regiunile trăiesc permanent în DOM**, goale când n-au ce arăta — nu se creează
 *    odată cu mesajul. O regiune live născută direct cu text în ea nu e anunțată fiabil:
 *    cititoarele de ecran urmăresc MUTAȚIILE dintr-o regiune pe care au înregistrat-o deja,
 *    nu apariția regiunii însăși. Varianta veche întorcea `null` cât timp nu era niciun
 *    mesaj, deci fix asta făcea.
 * 2. **Fiecare mesaj trece prin gol**: golire sincronă, apoi textul pe `requestAnimationFrame`.
 *    Fără asta, al DOILEA mesaj identic la rând nu produce nicio mutație — React iese din
 *    `setState` la valoare identică (`objectIs`, înainte de programarea randării), deci
 *    nodul de text rămâne neatins și regiunea n-are ce raporta. Primul succes se aude,
 *    următoarele sunt mute — exact pe fluxurile care repetă aceeași confirmare.
 *
 * Comparația e pe REFERINȚA obiectului `flash`, nu pe textul lui: Inertia produce un obiect
 * nou la fiecare răspuns de server, chiar când mesajul e identic, iar o comparație pe șir ar
 * reintroduce exact bug-ul pe care cele două condiții îl repară.
 */
export default function FlashMessages() {
    const { flash } = usePage().props;
    const [shown, setShown] = useState<Flash>(EMPTY);
    const previousRef = useRef<Flash | null>(null);

    useEffect(() => {
        if (previousRef.current === flash) {
            return;
        }

        previousRef.current = flash;
        setShown(EMPTY);

        const frame = requestAnimationFrame(() => setShown(flash));

        return () => cancelAnimationFrame(frame);
    }, [flash]);

    const hasMessage = Boolean(shown.success || shown.error || shown.notice);

    return (
        // Spațierea e condiționată, dar regiunile NU: goale, cele două `div`-uri au înălțime
        // zero și nu ocupă nimic, iar `display: none` (ex. `empty:hidden`) e interzis aici —
        // ar scoate regiunea din arborele de accesibilitate exact cât timp cititorul de ecran
        // ar trebui s-o înregistreze, adică ÎNAINTE de primul mesaj.
        <div className={`flex flex-col ${hasMessage ? 'mb-4 gap-2' : ''}`}>
            {/*
                `id="flash-status"` + `tabIndex={-1}` — ancoră de focus REFOLOSIBILĂ de orice
                pagină ale cărei acțiuni fac să dispară propriul declanșator (a treia formă a
                capcanei din `.ai/rules/frontend.md`, „Focusul nu se pierde niciodată pe
                `<body>`"). Prima folosire: `Settings/ApiTokens/Index.tsx`, după revocare —
                acolo pagina avea propriul `<div role="status" className="sr-only">` cu
                ACELAȘI text, invizibil pentru un utilizator de tastatură fără cititor de
                ecran (SC 2.4.7). Randând mesajul o SINGURĂ dată, aici, și mutând focusul spre
                EL, evităm atât duplicarea vizibilă cât și un al doilea anunț `aria-live`
                pentru conținut identic. Inelul de focus e explicit (nu `focus:outline-none`,
                ca la `Settings/Members/Index.tsx`/`Reports/Show.tsx`): elementul e PERSISTENT
                (nu apare/dispare o dată cu mesajul), deci apariția lui singură nu mai e un
                indiciu suficient de „aici a ajuns focusul".
            */}
            <div
                id="flash-status"
                tabIndex={-1}
                role="status"
                aria-live="polite"
                aria-atomic="true"
                className="flex flex-col gap-2 rounded-md focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus"
            >
                {shown.success && (
                    <p className="rounded-md bg-success-tint px-3 py-2 text-sm text-success">{shown.success}</p>
                )}
                {shown.notice && <p className="rounded-md bg-info-tint px-3 py-2 text-sm text-info">{shown.notice}</p>}
            </div>
            <div role="alert" aria-live="assertive" aria-atomic="true">
                {shown.error && <p className="rounded-md bg-danger-tint px-3 py-2 text-sm text-danger">{shown.error}</p>}
            </div>
        </div>
    );
}
