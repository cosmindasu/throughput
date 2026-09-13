import { router, usePage } from '@inertiajs/react';
import { useCallback, useEffect, useId, useMemo, useRef, useState, type KeyboardEvent as ReactKeyboardEvent } from 'react';
import type { SearchGroup, SearchResponse, SearchResult } from '@/types/generated';

const DEBOUNCE_MS = 200;

/**
 * Fără SSR în acest proiect (`resources/js/app.tsx` folosește `createRoot`, nu
 * `hydrateRoot`), deci citirea directă a `navigator` nu poate produce un
 * dezacord de hidratare — doar eticheta scurtăturii diferă (⌘K pe Mac, Ctrl K
 * în rest), nu logica de deschidere: `metaKey`/`ctrlKey` sunt verificate
 * amândouă la fiecare apăsare, indiferent de platformă.
 */
function isApplePlatform(): boolean {
    if (typeof navigator === 'undefined') {
        return false;
    }

    return /Mac|iPhone|iPad|iPod/.test(navigator.platform || navigator.userAgent);
}

/**
 * FR-SEARCH-01 — paleta Cmd+K. Interoghează `GET /{workspace}/search` (JSON simplu,
 * nu Inertia — paleta trăiește peste orice pagină, fără să-i schimbe starea).
 *
 * `<dialog>` nativ + `showModal()`, ca în `ConfirmDialog`: capcana de focus, Esc și
 * fundalul inert vin gratuit de la browser. Tiparul WAI-ARIA e „combobox cu listbox":
 * inputul e `role="combobox"`, rezultatele stau într-un `role="listbox"` grupat
 * (`role="group"` + `aria-labelledby` per etichetă), iar selecția activă se comunică
 * prin `aria-activedescendant` pe input, nu prin focus mutat pe fiecare opțiune —
 * altfel tastarea ar pierde focusul din câmp la fiecare săgeată.
 */
export default function GlobalSearch() {
    const { workspace } = usePage().props;

    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const [response, setResponse] = useState<SearchResponse | null>(null);
    const [activeIndex, setActiveIndex] = useState(0);

    const dialogRef = useRef<HTMLDialogElement>(null);
    const inputRef = useRef<HTMLInputElement>(null);
    const triggerRef = useRef<HTMLButtonElement>(null);
    /** Vezi fix-ul din efectul de `open` mai jos — citit DUPĂ `dialog.close()` nativ, nu înainte. */
    const shouldFocusTriggerOnCloseRef = useRef(false);
    const abortRef = useRef<AbortController | null>(null);
    const debounceRef = useRef<number | undefined>(undefined);

    const listboxId = useId();
    const isMac = useMemo(() => isApplePlatform(), []);

    // Cmd/Ctrl+K global — disponibil din orice ecran autentificat (FR-SEARCH-01). Ignorată
    // dacă un ALT `<dialog>` modal e deja deschis (ex: `ConfirmDialog`), la fel ca `?` din
    // `HelpPanel.tsx` — altfel paleta s-ar deschide vizual PESTE dialogul modal curent, fără
    // să-l închidă (fundalul lui rămâne inert, dar tastatura ajunge acum la paletă).
    useEffect(() => {
        const onKeyDown = (event: KeyboardEvent) => {
            if (!(event.metaKey || event.ctrlKey) || event.key.toLowerCase() !== 'k') {
                return;
            }

            const openDialog = document.querySelector('dialog[open]');

            if (openDialog && openDialog !== dialogRef.current) {
                return;
            }

            event.preventDefault();
            setOpen((wasOpen) => !wasOpen);
        };

        document.addEventListener('keydown', onKeyDown);
        return () => document.removeEventListener('keydown', onKeyDown);
    }, []);

    useEffect(() => {
        const dialog = dialogRef.current;

        if (!dialog) {
            return;
        }

        if (open && !dialog.open) {
            dialog.showModal();
        } else if (!open && dialog.open) {
            dialog.close();

            // FIX (bug găsit prin E2E, `e2e/specs/global-search.spec.ts`): `close()` pe un
            // `<dialog>` deschis prin `showModal()` restaurează focusul, NECONDIȚIONAT, pe
            // elementul care îl avea ÎNAINTE de `showModal()` — nu doar „dacă focusul mai e
            // încă în interiorul dialogului", cum ar sugera o citire superficială a
            // specificației. `close()` (funcția de mai jos) muta focusul pe declanșator
            // ÎNAINTE ca acest efect să apuce să cheme `dialog.close()` nativ — restaurarea
            // nativă câștiga mereu ULTIMA, trimițând focusul înapoi la orice era focusat
            // înainte de Cmd+K (adesea `<body>`), nu pe declanșator. Mutat aici, DUPĂ
            // `dialog.close()`, focusarea manuală chiar e ultimul cuvânt.
            if (shouldFocusTriggerOnCloseRef.current) {
                shouldFocusTriggerOnCloseRef.current = false;
                triggerRef.current?.focus();
            }
        }
    }, [open]);

    // Fără stare `loading` separată: `react-hooks/set-state-in-effect` respinge un
    // `setState` sincron chemat direct dintr-un efect (chiar și prin `fetchResults`),
    // fiindcă efectul ar trebui doar să pornească operația, nu să-i anunțe sincron
    // începutul. „Se încarcă" e deci DERIVAT: adevărat cât timp răspunsul cunoscut nu e
    // pentru termenul curent — devine fals abia în `.then()`, adică exact „setState într-un
    // callback declanșat de sistemul extern", tiparul pe care regula îl cere.
    //
    // `settledResponse` ÎNGUSTEAZĂ tipul, nu doar un boolean `loading`: oriunde e non-null,
    // TypeScript știe și că `query` corespunde răspunsului — fără asta, fiecare citire a lui
    // `response` sub „nu se mai încarcă" ar cere o afirmație non-null (P3, code review) pe
    // care compilatorul n-o poate verifica singur. „Se încarcă" e deci `settledResponse === null`.
    const settledResponse = response !== null && response.query === query ? response : null;

    const fetchResults = useCallback(
        (term: string) => {
            if (!workspace) {
                return;
            }

            abortRef.current?.abort();
            const controller = new AbortController();
            abortRef.current = controller;

            fetch(`/${workspace.slug}/search?q=${encodeURIComponent(term)}`, {
                headers: { Accept: 'application/json' },
                signal: controller.signal,
            })
                .then((res) => res.json() as Promise<SearchResponse>)
                .then((data) => {
                    setResponse(data);
                    setActiveIndex(0);
                })
                .catch((error: unknown) => {
                    // `AbortError` = cerere anulată de tastarea următoare (debounce) — nu o
                    // eroare de arătat utilizatorului.
                    if (error instanceof DOMException && error.name === 'AbortError') {
                        return;
                    }

                    setResponse({ query: term, groups: [] });
                });
        },
        [workspace],
    );

    // Resetul stării la deschidere se face ÎN RANDARE, nu într-un efect (tiparul React
    // recomandat pentru „ajustează starea când un prop se schimbă"). `wasOpen` ține
    // ultima valoare VĂZUTĂ, ca reset-ul să ruleze o singură dată per tranziție.
    const [wasOpen, setWasOpen] = useState(open);

    if (open !== wasOpen) {
        setWasOpen(open);

        if (open) {
            setQuery('');
            setActiveIndex(0);
            setResponse(null);
        }
    }

    // Partea care RĂMÂNE efect: interacțiunea cu sisteme externe (rețea, focus DOM).
    // Starea inițială (recent accesate + acțiuni), niciodată un ecran gol (FR-SEARCH-01)
    // — cererea pleacă imediat, cu termenul gol.
    useEffect(() => {
        if (!open) {
            abortRef.current?.abort();
            window.clearTimeout(debounceRef.current);
            return;
        }

        fetchResults('');

        const frame = requestAnimationFrame(() => inputRef.current?.focus());
        return () => cancelAnimationFrame(frame);
    }, [open, fetchResults]);

    const onQueryChange = (value: string) => {
        setQuery(value);
        window.clearTimeout(debounceRef.current);
        debounceRef.current = window.setTimeout(() => fetchResults(value), DEBOUNCE_MS);
    };

    const flatResults = useMemo(() => (response?.groups ?? []).flatMap((group) => group.results), [response]);

    // Grupurile cu poziția lor de start în `flatResults`, ca `aria-activedescendant`
    // (pe input) și evidențierea la hover să adreseze un index GLOBAL, peste grupuri —
    // exact ce cere navigarea cu săgeți din FR-SEARCH-01.
    const groupsWithOffsets = useMemo(
        () =>
            (response?.groups ?? []).reduce<Array<{ group: SearchGroup; startIndex: number }>>((withOffsets, group) => {
                const previous = withOffsets[withOffsets.length - 1];
                const startIndex = previous ? previous.startIndex + previous.group.results.length : 0;

                return [...withOffsets, { group, startIndex }];
            }, []),
        [response],
    );

    const close = (focusTrigger = true) => {
        shouldFocusTriggerOnCloseRef.current = focusTrigger;
        setOpen(false);
    };

    const select = (result: SearchResult) => {
        close(false);
        router.visit(result.url);
    };

    const onInputKeyDown = (event: ReactKeyboardEvent<HTMLInputElement>) => {
        switch (event.key) {
            case 'Escape':
                // `<dialog>` închide singur la Esc; interceptăm ca să readucem focusul
                // explicit pe declanșator (nu doar „undeva").
                event.preventDefault();
                close();
                break;
            case 'ArrowDown':
                event.preventDefault();
                setActiveIndex((index) => Math.min(index + 1, flatResults.length - 1));
                break;
            case 'ArrowUp':
                event.preventDefault();
                setActiveIndex((index) => Math.max(index - 1, 0));
                break;
            case 'Enter': {
                event.preventDefault();
                const result = flatResults[activeIndex];

                if (result) {
                    select(result);
                }

                break;
            }
        }
    };

    const statusText =
        settledResponse === null
            ? 'Searching…'
            : settledResponse.groups.length === 0
              ? settledResponse.query
                  ? `No results for "${settledResponse.query}"`
                  : 'Nothing here yet — start typing to search.'
              : `${flatResults.length} result${flatResults.length === 1 ? '' : 's'}`;

    return (
        <>
            <button
                ref={triggerRef}
                type="button"
                disabled={!workspace}
                onClick={() => setOpen(true)}
                className="flex items-center gap-2 rounded-md border border-control px-3 py-1.5 text-sm text-text-2 transition-colors hover:bg-row-hover hover:text-text focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus disabled:cursor-not-allowed disabled:opacity-60"
            >
                <span aria-hidden="true">⌕</span>
                <span className="hidden sm:inline">Search</span>
                <kbd
                    aria-hidden="true"
                    className="hidden rounded border border-border-soft bg-raised px-1.5 py-0.5 text-xs text-text-3 sm:inline"
                >
                    {isMac ? '⌘K' : 'Ctrl K'}
                </kbd>
            </button>

            <dialog
                ref={dialogRef}
                aria-label="Global search"
                onClose={() => close(false)}
                onClick={(event) => {
                    // Click pe `::backdrop` ajunge cu `target` = elementul `<dialog>` însuși.
                    // Aceeași cale ca Esc (`close()`, focus pe declanșator) — un click în afara
                    // paletei nu e mai puțin „o închidere" decât tasta Esc.
                    if (event.target === dialogRef.current) {
                        close();
                    }
                }}
                // Fără tranziție de intrare/ieșire: `<dialog>` nativ apare/dispare instant,
                // deci `prefers-reduced-motion` e respectat prin absență, nu prin varianta
                // `motion-safe:` — n-are ce dezactiva. O animație de fade/scale ar cere
                // `@starting-style` (fără utilitar Tailwind 4 nativ) sau o dependință nouă,
                // ambele evitate deliberat (§ instrucțiuni pachet G — nicio dependință nouă).
                className="m-auto w-[min(36rem,92vw)] rounded-lg border border-border bg-overlay p-0 text-text backdrop:bg-scrim"
            >
                <div className="flex items-center gap-2 border-b border-border-soft px-4 py-3">
                    <span aria-hidden="true" className="text-text-3">
                        ⌕
                    </span>
                    <input
                        ref={inputRef}
                        type="text"
                        role="combobox"
                        aria-expanded={open}
                        aria-controls={listboxId}
                        aria-autocomplete="list"
                        aria-activedescendant={flatResults[activeIndex] ? `${listboxId}-option-${activeIndex}` : undefined}
                        value={query}
                        onChange={(event) => onQueryChange(event.target.value)}
                        onKeyDown={onInputKeyDown}
                        placeholder="Search accounts, contacts, deals…"
                        className="w-full bg-transparent text-sm text-text placeholder:text-text-3 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus"
                    />
                </div>

                <span className="sr-only" role="status" aria-live="polite">
                    {statusText}
                </span>

                <div id={listboxId} role="listbox" aria-label="Search results" className="max-h-96 overflow-y-auto py-2">
                    {settledResponse !== null && settledResponse.groups.length === 0 && (
                        <p className="px-4 py-6 text-center text-sm text-text-3">{statusText}</p>
                    )}

                    {settledResponse !== null &&
                        groupsWithOffsets.map(({ group, startIndex }) => (
                            <SearchGroupList
                                key={group.type}
                                group={group}
                                listboxId={listboxId}
                                startIndex={startIndex}
                                activeIndex={activeIndex}
                                onHover={setActiveIndex}
                                onSelect={select}
                            />
                        ))}
                </div>
            </dialog>
        </>
    );
}

interface SearchGroupListProps {
    group: SearchGroup;
    listboxId: string;
    startIndex: number;
    activeIndex: number;
    onHover: (index: number) => void;
    onSelect: (result: SearchResult) => void;
}

function SearchGroupList({ group, listboxId, startIndex, activeIndex, onHover, onSelect }: SearchGroupListProps) {
    const groupLabelId = `${listboxId}-group-${group.type}`;

    return (
        <div role="group" aria-labelledby={groupLabelId}>
            <p id={groupLabelId} className="px-4 pt-2 pb-1 text-xs font-medium tracking-wide text-text-3 uppercase">
                {group.label}
            </p>

            {group.results.map((result, offset) => {
                const index = startIndex + offset;
                const active = index === activeIndex;

                return (
                    <div
                        key={`${result.type}-${result.id}`}
                        id={`${listboxId}-option-${index}`}
                        role="option"
                        aria-selected={active}
                        onMouseEnter={() => onHover(index)}
                        onClick={() => onSelect(result)}
                        className={`mx-2 cursor-pointer rounded-md px-3 py-2 text-sm ${active ? 'bg-row-hover text-text' : 'text-text-2'}`}
                    >
                        <div className="font-medium text-text">{result.label}</div>
                        {result.sublabel && <div className="text-xs text-text-3">{result.sublabel}</div>}
                    </div>
                );
            })}
        </div>
    );
}
