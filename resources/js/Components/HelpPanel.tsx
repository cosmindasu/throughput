import { router, usePage } from '@inertiajs/react';
import { useEffect, useId, useRef, useState, type RefObject } from 'react';
import { Trans, useTranslation } from 'react-i18next';
import { helpTopicDefinitionForComponent } from '@/help';
import { helpCatalogIsLoaded, loadHelpCatalog } from '@/help/catalog';
import { useHelpTopic } from '@/help/useHelpTopic';
import { useLocale } from '@/hooks/useLocale';
import type { AppLocale } from '@/lib/i18n';

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
    const { t } = useTranslation('common');
    const locale = useLocale();
    const page = usePage();
    // DEFINIȚIA, nu subiectul complet: de ea depinde dacă butonul „?" se randează, iar acea
    // decizie e sincronă prin construcție (vezi `help/catalog.ts` — textul vine leneș).
    const topic = helpTopicDefinitionForComponent(page.component);
    const dismissedHints = page.props.auth.user?.dismissedHints ?? [];
    const introHintDismissed = dismissedHints.includes(INTRO_HINT_KEY);

    const [open, setOpen] = useState(false);
    // Esc ascunde tooltip-ul fără a muta focusul sau cursorul (WCAG 1.4.13, „dismissible").
    const [hintSuppressed, setHintSuppressed] = useState(false);
    // Limba al cărei catalog de ajutor e încărcat. `null` = niciunul încă. Comparat cu
    // `locale` (nu un boolean): `LocaleToggle` comută limba fără încărcare completă de
    // pagină, deci „gata" înseamnă „gata PENTRU LIMBA CURENTĂ", nu „s-a încărcat cândva".
    const [catalogLocale, setCatalogLocale] = useState<AppLocale | null>(() =>
        helpCatalogIsLoaded(locale) ? locale : null
    );
    const catalogReady = catalogLocale === locale;
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

    // Catalogul limbii active, adus la PRIMA deschidere — nu la montarea layout-ului.
    // Vezi `help/catalog.ts` pentru cifra care motivează încărcarea leneșă.
    useEffect(() => {
        if (!open || catalogReady) {
            return;
        }

        let cancelled = false;
        void loadHelpCatalog(locale).then(() => {
            if (! cancelled) {
                setCatalogLocale(locale);
            }
        });

        return () => {
            cancelled = true;
        };
    }, [open, locale, catalogReady]);

    // La deschidere, focusul intră în panou (pe titlu, `tabIndex=-1`) — util mai
    // ales pentru cine a deschis cu tastatura. Panoul rămâne NECAPTIV: Tab poate
    // ieși din el înapoi în pagină, pentru că pagina rămâne interactivă.
    //
    // Depinde ȘI de `catalogReady`, fiindcă titlul pe care cade focusul se montează abia
    // odată cu conținutul: la prima deschidere din sesiune, efectul rulează a doua oară,
    // după commit-ul care a randat titlul, când `headingRef` chiar are pe ce să cadă.
    useEffect(() => {
        if (open && catalogReady) {
            headingRef.current?.focus();
        }
    }, [open, catalogReady]);

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

    // Indiciul NU mai e o bulă permanentă peste navigație. Două semnale, ambele necaptive:
    //  - un punct pulsatoriu pe butonul „?" (decor, `aria-hidden`) cât timp indiciul n-a fost închis;
    //  - textul, ca TOOLTIP real (`role="tooltip"` + `aria-describedby`), doar la hover/focus pe buton.
    // WCAG 1.4.13: dismissible (Esc), hoverable (tooltip-ul e copil al aceluiași `group`) și persistent
    // cât ține hover/focus. Nu mai stă în arborele de accesibilitate ca `role="status"` pe fiecare pagină.
    const introHintVisible = !open && !introHintDismissed && !hintSuppressed;
    const hintId = `${panelId}-hint`;

    return (
        <div className="group relative">
            <button
                ref={triggerRef}
                type="button"
                aria-expanded={open}
                aria-controls={panelId}
                aria-describedby={introHintVisible ? hintId : undefined}
                onClick={() => (open ? close() : openPanel())}
                onKeyDown={(event) => event.key === 'Escape' && setHintSuppressed(true)}
                className="relative flex h-8 w-8 items-center justify-center rounded-full border border-control text-sm font-medium text-text-2 transition-colors hover:bg-row-hover hover:text-text focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus"
            >
                <span aria-hidden="true">?</span>
                <span className="sr-only">{t('common:helpPanel.trigger')}</span>
                {introHintVisible && (
                    <span aria-hidden="true" className="absolute -right-0.5 -top-0.5 size-2.5 rounded-full bg-accent-fill ring-2 ring-surface motion-safe:animate-pulse" />
                )}
            </button>

            {introHintVisible && (
                <div
                    id={hintId}
                    role="tooltip"
                    className="invisible absolute right-0 top-full z-30 mt-2 w-64 rounded-md border border-border bg-overlay p-3 text-sm text-text opacity-0 shadow-lg transition-opacity duration-150 group-focus-within:visible group-focus-within:opacity-100 group-hover:visible group-hover:opacity-100 motion-reduce:transition-none"
                >
                    <p>
                        <Trans
                            i18nKey="common:helpPanel.introHint"
                            components={{ kbd: <kbd className="rounded border border-control px-1 font-mono text-xs" /> }}
                        />
                    </p>
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
                // Eticheta regiunii vine din catalog, nu din „Help: " + titlul subiectului
                // concatenat în cod: a fost singurul șir englez rămas hardcodat în această
                // componentă după Valul 3, și oricum titlul nu e disponibil până se încarcă
                // catalogul. Numele subiectului rămâne vizibil pe `<h2>`-ul din interior,
                // deci nu se pierde nimic pentru cititorul de ecran — doar nu se mai
                // dublează într-o limbă greșită.
                aria-label={t('common:helpPanel.regionLabel')}
                className={`fixed inset-y-0 right-0 z-40 w-[min(28rem,100vw)] transform overflow-y-auto border-l border-border bg-surface shadow-lg transition-transform duration-200 ease-out motion-reduce:transition-none ${
                    open ? 'translate-x-0' : 'pointer-events-none translate-x-full'
                }`}
            >
                {open &&
                    (catalogReady ? (
                        <HelpPanelContent component={page.component} headingRef={headingRef} onClose={close} />
                    ) : (
                        <p role="status" className="p-6 text-sm text-text-2">
                            {t('common:helpPanel.loading')}
                        </p>
                    ))}
            </aside>
        </div>
    );
}

interface HelpPanelContentProps {
    component: string;
    headingRef: RefObject<HTMLHeadingElement | null>;
    onClose: () => void;
}

/**
 * Structura fixă în patru părți, în ordine (FR-HELP-02): ce e pagina asta → ce
 * poți face aici → regulile care se aplică → cum e construit (pliat, închis
 * implicit — audiența secundară din specs.md §1.5).
 */
function HelpPanelContent({ component, headingRef, onClose }: HelpPanelContentProps) {
    const { t } = useTranslation('common');
    const topic = useHelpTopic(component);

    // Imposibil în practică: părintele randează asta doar după ce a găsit o definiție ȘI
    // a încărcat catalogul. Ramura există pentru că `useHelpTopic` e cinstit în tip.
    if (!topic) {
        return null;
    }

    return (
        <div className="flex h-full flex-col gap-5 p-6">
            <div className="flex items-start justify-between gap-4">
                <h2 ref={headingRef} tabIndex={-1} className="text-lg font-semibold text-text focus:outline-none">
                    {topic.title}
                </h2>
                <button
                    type="button"
                    onClick={onClose}
                    aria-label={t('common:helpPanel.close')}
                    className="shrink-0 rounded-md p-1 text-text-2 hover:bg-row-hover hover:text-text focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus"
                >
                    <span aria-hidden="true">✕</span>
                </button>
            </div>

            <p className="text-sm text-text-2">{topic.whatIsThis}</p>

            <section aria-labelledby={`${topic.id}-what-you-can-do`}>
                <h3 id={`${topic.id}-what-you-can-do`} className="text-sm font-semibold text-text">
                    {t('common:helpPanel.whatYouCanDo')}
                </h3>
                <ul className="mt-2 list-disc space-y-1.5 pl-5 text-sm text-text-2">
                    {topic.whatCanYouDo.map((action) => (
                        <li key={action}>{action}</li>
                    ))}
                </ul>
            </section>

            <section aria-labelledby={`${topic.id}-rules`}>
                <h3 id={`${topic.id}-rules`} className="text-sm font-semibold text-text">
                    {t('common:helpPanel.rules')}
                </h3>
                <ul className="mt-2 list-disc space-y-1.5 pl-5 text-sm text-text-2">
                    {topic.rules.map((rule) => (
                        <li key={rule}>{rule}</li>
                    ))}
                </ul>
            </section>

            {/* Pliat, ÎNCHIS implicit — nu se deschide singur (BR-HELP-02, prin analogie). */}
            <details className="rounded-md border border-border-soft bg-raised p-3 text-sm">
                <summary className="cursor-pointer font-semibold text-text">{t('common:helpPanel.howItsBuilt')}</summary>
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
                            {/* A11Y-11 (G201) — cheia trăiește în catalogul `help` (namespace deja
                                încărcat de îndată ce ajunge aici, garantat de `catalogReady` mai sus),
                                nu în `common`, ca să nu ating `common.json`. */}
                            <span className="sr-only"> {t('help:externalLink.opensInNewTab')}</span>
                        </a>
                    </p>
                )}
            </details>
        </div>
    );
}
