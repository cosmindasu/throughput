import { useEffect, useId, useRef, useState, type KeyboardEvent as ReactKeyboardEvent } from 'react';

/** Ce a declanșat ultima acțiune — pentru anunțul `aria-live` (SC 4.1.3), calculat DUPĂ ce `selected` s-a schimbat cu adevărat, nu optimist. */
interface PendingAnnouncement {
    key: string;
    label: string;
    type: 'toggle' | 'move';
}

export interface ColumnDefinition {
    key: string;
    label: string;
}

interface ColumnSelectorProps {
    /** Toate coloanele PERMISE ale resursei (`SavedViewResourceType::permittedColumns()`), în ordinea canonică/implicită. */
    columns: ColumnDefinition[];
    /** Coloanele VIZIBILE curent, în ordinea lor de afișare — exact `?columns=`. */
    selected: string[];
    onToggle: (key: string) => void;
    onMoveUp: (key: string) => void;
    onMoveDown: (key: string) => void;
}

/**
 * Selector generic de coloane — specs.md §15.1: „Meniu cu bife și reordonare prin
 * «Move up/Move down», operabil integral de la tastatură. Fără drag ca singură cale de
 * reordonare (WCAG 2.2 SC 2.5.7)." Coloana de identificare (nume/titlu/număr), checkbox-ul
 * de bulk și coloana de acțiuni NU apar aici — pagina le randează fix, în afara setului
 * configurabil.
 *
 * Tipar disclosure (buton + panou), ca `SavedViewPicker.tsx`: Escape închide și readuce
 * focusul pe declanșator, click în afara panoului la fel. Rândurile sunt elemente native
 * (checkbox + două butoane), Tab-navigabile în ordinea din DOM — niciun `role`/keydown
 * custom peste ele.
 */
export default function ColumnSelector({ columns, selected, onToggle, onMoveUp, onMoveDown }: ColumnSelectorProps) {
    const [open, setOpen] = useState(false);
    const buttonRef = useRef<HTMLButtonElement>(null);
    const panelRef = useRef<HTMLDivElement>(null);
    const panelId = useId();
    const hintId = `${panelId}-hint`;

    const [announcement, setAnnouncement] = useState('');
    const pendingAnnouncementRef = useRef<PendingAnnouncement | null>(null);
    const previousSelectedRef = useRef<string[]>(selected);

    // SC 4.1.3 — anunțat DUPĂ ce `selected` s-a schimbat efectiv (răspunsul Inertia s-a
    // întors), nu optimist la click: la o mutare/bifare blocată la capăt (no-op în
    // `useListColumns`), `selected` nu se schimbă, deci nu se anunță nimic — corect, fiindcă
    // n-a avut loc nicio schimbare reală.
    useEffect(() => {
        const previous = previousSelectedRef.current;
        const pending = pendingAnnouncementRef.current;
        previousSelectedRef.current = selected;

        if (!pending) {
            return;
        }
        pendingAnnouncementRef.current = null;

        if (pending.type === 'toggle') {
            const wasSelected = previous.includes(pending.key);
            const isSelectedNow = selected.includes(pending.key);
            if (wasSelected !== isSelectedNow) {
                setAnnouncement(`${pending.label} ${isSelectedNow ? 'shown' : 'hidden'}.`);
            }
            return;
        }

        const changed = previous.length !== selected.length || previous.some((key, index) => key !== selected[index]);
        const index = selected.indexOf(pending.key);
        if (changed && index !== -1) {
            setAnnouncement(`${pending.label} moved to position ${index + 1} of ${selected.length}.`);
        }
    }, [selected]);

    const toggleWithAnnouncement = (column: ColumnDefinition) => {
        pendingAnnouncementRef.current = { key: column.key, label: column.label, type: 'toggle' };
        onToggle(column.key);
    };

    const moveUpWithAnnouncement = (column: ColumnDefinition) => {
        pendingAnnouncementRef.current = { key: column.key, label: column.label, type: 'move' };
        onMoveUp(column.key);
    };

    const moveDownWithAnnouncement = (column: ColumnDefinition) => {
        pendingAnnouncementRef.current = { key: column.key, label: column.label, type: 'move' };
        onMoveDown(column.key);
    };

    useEffect(() => {
        if (!open) {
            return;
        }

        const onPointerDown = (event: MouseEvent) => {
            const target = event.target as Node;
            if (!panelRef.current?.contains(target) && !buttonRef.current?.contains(target)) {
                setOpen(false);
            }
        };

        document.addEventListener('mousedown', onPointerDown);
        return () => document.removeEventListener('mousedown', onPointerDown);
    }, [open]);

    const closePanel = () => {
        setOpen(false);
        buttonRef.current?.focus();
    };

    const onPanelKeyDown = (event: ReactKeyboardEvent<HTMLDivElement>) => {
        if (event.key === 'Escape') {
            event.preventDefault();
            closePanel();
        }
    };

    // Bifate întâi, în ordinea lor de afișare, apoi nebifate în ordinea canonică — o listă
    // stabilă, ca poziția unui rând să nu sară în timp ce utilizatorul bifează/debifează.
    const ordered = [
        ...selected.map((key) => columns.find((column) => column.key === key)).filter((column): column is ColumnDefinition => column !== undefined),
        ...columns.filter((column) => !selected.includes(column.key)),
    ];

    return (
        <div className="relative">
            <button
                ref={buttonRef}
                type="button"
                aria-expanded={open}
                aria-controls={panelId}
                onClick={() => (open ? closePanel() : setOpen(true))}
                className="flex items-center gap-2 rounded-md border border-control px-3 py-1.5 text-sm text-text transition-colors hover:bg-row-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus"
            >
                <span>Columns</span>
                <span aria-hidden="true">▾</span>
            </button>

            {/* SC 4.1.3 — montată necondiționat (nu doar cât panoul e deschis), ca cititorul de
                ecran s-o fi înregistrat deja înainte de prima bifă/mutare din sesiune. */}
            <div aria-live="polite" className="sr-only">
                {announcement}
            </div>

            {open && (
                <div
                    id={panelId}
                    ref={panelRef}
                    role="group"
                    aria-label="Visible columns"
                    onKeyDown={onPanelKeyDown}
                    className="absolute right-0 z-20 mt-1 w-72 rounded-md border border-border bg-overlay p-2 text-sm shadow-lg"
                >
                    <ul className="flex flex-col gap-0.5">
                        {ordered.map((column) => {
                            const isSelected = selected.includes(column.key);
                            const selectedIndex = selected.indexOf(column.key);
                            const checkboxId = `${panelId}-${column.key}`;
                            // FR-VIEW-01 corolar — cel puțin o coloană rămâne vizibilă: ultima
                            // bifată nu se poate debifa (gardă reală în `useListColumns.toggle`,
                            // aici doar semantica + hint-ul vizibil pentru ea).
                            const isOnlySelected = isSelected && selected.length === 1;
                            const upDisabled = !isSelected || selectedIndex <= 0;
                            const downDisabled = !isSelected || selectedIndex === selected.length - 1;

                            return (
                                <li key={column.key} className="flex items-center justify-between gap-2 rounded px-2 py-1.5 hover:bg-row-hover">
                                    <label
                                        htmlFor={checkboxId}
                                        className={`flex flex-1 items-center gap-2 truncate ${isOnlySelected ? 'text-text-3' : ''}`}
                                    >
                                        <input
                                            id={checkboxId}
                                            type="checkbox"
                                            checked={isSelected}
                                            aria-disabled={isOnlySelected}
                                            aria-describedby={isOnlySelected ? hintId : undefined}
                                            onChange={() => toggleWithAnnouncement(column)}
                                            className="size-4 rounded border-control focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus"
                                        />
                                        <span className="truncate text-text">{column.label}</span>
                                    </label>

                                    <div className="flex shrink-0 items-center gap-1 text-text-3">
                                        {/* `aria-disabled`, NU `disabled`: la capătul listei, un buton
                                            nativ dezactivat iese din ordinea de tab și, dacă avea
                                            focusul, îl pierde pe `<body>` — P1 din audit. Rămâne
                                            focusabil; handler-ul e deja un no-op la capăt
                                            (`useListColumns.moveUp/moveDown`). Target size ≥24×24
                                            (SC 2.5.8) — `size-6`. */}
                                        <button
                                            type="button"
                                            onClick={() => moveUpWithAnnouncement(column)}
                                            aria-disabled={upDisabled}
                                            aria-label={`Move ${column.label} up`}
                                            className={`flex size-6 items-center justify-center rounded hover:text-text focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-focus ${upDisabled ? 'opacity-30' : ''}`}
                                        >
                                            <span aria-hidden="true">↑</span>
                                        </button>
                                        <button
                                            type="button"
                                            onClick={() => moveDownWithAnnouncement(column)}
                                            aria-disabled={downDisabled}
                                            aria-label={`Move ${column.label} down`}
                                            className={`flex size-6 items-center justify-center rounded hover:text-text focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-focus ${downDisabled ? 'opacity-30' : ''}`}
                                        >
                                            <span aria-hidden="true">↓</span>
                                        </button>
                                    </div>
                                </li>
                            );
                        })}
                    </ul>

                    <p id={hintId} className="mt-2 border-t border-border-soft pt-2 text-xs text-text-3">
                        At least one column stays visible.
                    </p>
                </div>
            )}
        </div>
    );
}
