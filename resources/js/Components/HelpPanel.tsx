import { router, usePage } from '@inertiajs/react';
import { useEffect, useId, useRef, useState, type RefObject } from 'react';
import { helpTopicForComponent } from '@/help';
import type { HelpTopic } from '@/help/types';

/**
 * Cheia indiciului de primă vizită despre PANOUL DE AJUTOR însuși (BR-HELP-02) —
 * nu una per pagină: ce trebuie „descoperit" o singură dată e faptul că `?`
 * există, nu conținutul fiecărui ecran în parte. `POST /hints/{key}` e generic
 * (routes/web/hints.php) — orice alt ecran poate folosi același endpoint cu altă
 * cheie, mai târziu, fără să schimbe nimic aici.
 */
const INTRO_HINT_KEY = 'help-panel-intro';

/**
 * FR-HELP-01…04, BR-HELP-01…04 — butonul `?` din bara de sus și panoul lateral
 * de ajutor. Stub-ul fundației Fazei 2 fixase deja locul din `AppLayout`; asta e
 * implementarea.
 *
 * NEBLOCANT în mod deliberat (BR-HELP-01, FR-HELP-01): spre deosebire de
 * `ConfirmDialog` (care folosește `<dialog>` + `showModal()`, cu capcană de
 * focus și fundal inert), panoul de aici e un `<aside>` obișnuit — pagina din
 * spate rămâne complet vizibilă și interactivă, ca utilizatorul să citească
 * ajutorul uitându-se la ecranul pe care îl descrie, nu la o fereastră care îl
 * acoperă.
 *
 * O pagină fără subiect nu arată un buton gol: `helpTopicForComponent` întoarce
 * `null`, iar componenta randează `null` — situație imposibilă pe rutele din
 * navigația principală plus Dashboard, garantat de
 * `tests/Feature/Help/HelpTopicCoverageTest.php` (FR-HELP-04).
 */
export default function HelpPanel() {
    const page = usePage();
    const topic = helpTopicForComponent(page.component);
    const dismissedHints = page.props.auth.user?.dismissedHints ?? [];
    const introHintDismissed = dismissedHints.includes(INTRO_HINT_KEY);

    const [open, setOpen] = useState(false);
    const triggerRef = useRef<HTMLButtonElement>(null);
    const headingRef = useRef<HTMLHeadingElement>(null);
    const panelId = useId();

    // „Mereu la zi", citită dintr-un listener de `document` atașat O SINGURĂ
    // dată (vezi efectul mai jos) — altfel fiecare navigare (schimbă `topic`)
    // sau fiecare respingere de indiciu (schimbă `dismissedHints`) ar cere
    // reatașarea listener-ului global doar ca să vadă valori proaspete. Scrisă
    // dintr-un efect, NICIODATĂ direct în corpul funcției: citirea/scrierea unui
    // ref în timpul randării nu e sigură (react-hooks/refs).
    const latest = useRef({ open, topic, introHintDismissed });
    useEffect(() => {
        latest.current = { open, topic, introHintDismissed };
    });

    // Navigarea poate duce spre o pagină fără subiect (modul încă neconstruit) —
    // panoul nu trebuie să rămână „deschis" intern, gata să reapară deja
    // deschis dacă utilizatorul revine la o pagină cu subiect. Ajustare de stare
    // ÎN TIMPUL randării (pattern-ul recomandat de React pentru „reset state
    // when a prop changes"), NU într-un efect — un `setState` sincron într-un
    // efect ar declanșa un randare în cascadă (react-hooks/set-state-in-effect).
    const [lastTopicId, setLastTopicId] = useState(topic?.id ?? null);
    if ((topic?.id ?? null) !== lastTopicId) {
        setLastTopicId(topic?.id ?? null);
        if (!topic && open) {
            setOpen(false);
        }
    }

    const dismissIntroHint = () => {
        if (latest.current.introHintDismissed) {
            return;
        }
        // Idempotent server-side (HintController) — un al doilea click/`?` între
        // timp nu produce o a doua intrare și nu eșuează.
        router.post(`/hints/${INTRO_HINT_KEY}`, {}, { preserveScroll: true, preserveState: true });
    };

    const openPanel = () => {
        dismissIntroHint();
        setOpen(true);
    };

    const close = () => {
        setOpen(false);
        // BR-HELP-03 — focusul revine mereu pe declanșator, indiferent dacă
        // închiderea a venit din `Esc`, din butonul „Close" sau din tastarea „?".
        triggerRef.current?.focus();
    };

    // La deschidere, focusul intră în panou (pe titlu, `tabIndex=-1`) — util mai
    // ales pentru cine a deschis cu tastatura. Panoul rămâne NECAPTIV: Tab poate
    // ieși din el înapoi în pagină, pentru că pagina rămâne interactivă.
    useEffect(() => {
        if (open) {
            headingRef.current?.focus();
        }
    }, [open]);

    useEffect(() => {
        function onKeyDown(event: KeyboardEvent) {
            const { open: isOpen, topic: currentTopic, introHintDismissed: dismissed } = latest.current;

            if (event.key === 'Escape') {
                if (isOpen) {
                    setOpen(false);
                    triggerRef.current?.focus();
                }
                return;
            }

            if (event.key !== '?' || event.metaKey || event.ctrlKey || event.altKey || !currentTopic) {
                return;
            }

            const target = event.target as HTMLElement | null;
            const tag = target?.tagName;
            const isTypingTarget = tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || Boolean(target?.isContentEditable);

            // BR-HELP-03 — ignorat cât timp focusul e într-un câmp de text (nu se
            // bate cu un „?" tastat într-un search box) și nu deschide panoul ÎN
            // SPATELE unui `<dialog>` modal deja deschis (ex: `ConfirmDialog`),
            // care altfel ar capta tastatura vizual, dar nu ar opri acest listener
            // de pe `document`.
            if (isTypingTarget || document.querySelector('dialog[open]')) {
                return;
            }

            event.preventDefault();

            if (isOpen) {
                setOpen(false);
                triggerRef.current?.focus();
                return;
            }

            if (!dismissed) {
                router.post(`/hints/${INTRO_HINT_KEY}`, {}, { preserveScroll: true, preserveState: true });
            }
            setOpen(true);
        }

        document.addEventListener('keydown', onKeyDown);
        return () => document.removeEventListener('keydown', onKeyDown);
    }, []);

    if (!topic) {
        return null;
    }

    const introHintVisible = !open && !introHintDismissed;

    return (
        <div className="relative">
            <button
                ref={triggerRef}
                type="button"
                aria-expanded={open}
                aria-controls={panelId}
                onClick={() => (open ? close() : openPanel())}
                className="flex h-8 w-8 items-center justify-center rounded-full border border-control text-sm font-medium text-text-2 transition-colors hover:bg-row-hover hover:text-text focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus"
            >
                <span aria-hidden="true">?</span>
                <span className="sr-only">Help for this page</span>
            </button>

            {introHintVisible && (
                <div
                    role="status"
                    className="absolute right-0 top-full z-30 mt-2 w-64 rounded-md border border-border bg-overlay p-3 text-sm text-text shadow-lg"
                >
                    <p>
                        New here? Press{' '}
                        <kbd className="rounded border border-control px-1 font-mono text-xs">?</kbd> or click this
                        button for help about the page you&apos;re on.
                    </p>
                    <button
                        type="button"
                        onClick={dismissIntroHint}
                        className="mt-2 text-xs font-medium text-accent-text underline-offset-2 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus"
                    >
                        Got it
                    </button>
                </div>
            )}

            {/*
             * Mereu montat (nu doar cât `open`), ca schimbarea de clasă
             * `translate-x-*` să fie o TRANZIȚIE, nu o apariție instantă — dar
             * conținutul din interior se montează doar la `open`, ca elementele
             * focusabile din panou să nu rămână în ordinea de tabulare cât
             * panoul e „împins" în afara ecranului (BR-HELP-03).
             */}
            <aside
                id={panelId}
                aria-hidden={!open}
                aria-label={`Help: ${topic.title}`}
                className={`fixed inset-y-0 right-0 z-40 w-[min(28rem,100vw)] transform overflow-y-auto border-l border-border bg-surface shadow-lg transition-transform duration-200 ease-out motion-reduce:transition-none ${
                    open ? 'translate-x-0' : 'pointer-events-none translate-x-full'
                }`}
            >
                {open && <HelpPanelContent topic={topic} headingRef={headingRef} onClose={close} />}
            </aside>
        </div>
    );
}

interface HelpPanelContentProps {
    topic: HelpTopic;
    headingRef: RefObject<HTMLHeadingElement | null>;
    onClose: () => void;
}

/**
 * Structura fixă în patru părți, în ordine (FR-HELP-02): ce e pagina asta → ce
 * poți face aici → regulile care se aplică → cum e construit (pliat, închis
 * implicit — audiența secundară din specs.md §1.5).
 */
function HelpPanelContent({ topic, headingRef, onClose }: HelpPanelContentProps) {
    return (
        <div className="flex h-full flex-col gap-5 p-6">
            <div className="flex items-start justify-between gap-4">
                <h2 ref={headingRef} tabIndex={-1} className="text-lg font-semibold text-text focus:outline-none">
                    {topic.title}
                </h2>
                <button
                    type="button"
                    onClick={onClose}
                    aria-label="Close help panel"
                    className="shrink-0 rounded-md p-1 text-text-2 hover:bg-row-hover hover:text-text focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus"
                >
                    <span aria-hidden="true">✕</span>
                </button>
            </div>

            <p className="text-sm text-text-2">{topic.whatIsThis}</p>

            <section aria-labelledby={`${topic.id}-what-you-can-do`}>
                <h3 id={`${topic.id}-what-you-can-do`} className="text-sm font-semibold text-text">
                    What you can do here
                </h3>
                <ul className="mt-2 list-disc space-y-1.5 pl-5 text-sm text-text-2">
                    {topic.whatCanYouDo.map((action) => (
                        <li key={action}>{action}</li>
                    ))}
                </ul>
            </section>

            <section aria-labelledby={`${topic.id}-rules`}>
                <h3 id={`${topic.id}-rules`} className="text-sm font-semibold text-text">
                    Rules that apply here
                </h3>
                <ul className="mt-2 list-disc space-y-1.5 pl-5 text-sm text-text-2">
                    {topic.rules.map((rule) => (
                        <li key={rule}>{rule}</li>
                    ))}
                </ul>
            </section>

            {/* Pliat, ÎNCHIS implicit — nu se deschide singur (BR-HELP-02, prin analogie). */}
            <details className="rounded-md border border-border-soft bg-raised p-3 text-sm">
                <summary className="cursor-pointer font-semibold text-text">How it&apos;s built</summary>
                <p className="mt-2 text-text-2">{topic.howItsBuilt.summary}</p>
                {topic.howItsBuilt.adr && (
                    <p className="mt-2">
                        <a
                            href={topic.howItsBuilt.adr.url}
                            target="_blank"
                            rel="noreferrer"
                            className="font-medium text-accent-text underline-offset-2 hover:underline"
                        >
                            {topic.howItsBuilt.adr.id}: {topic.howItsBuilt.adr.title}
                        </a>
                    </p>
                )}
            </details>
        </div>
    );
}
