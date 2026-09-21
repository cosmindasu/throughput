import { usePage } from '@inertiajs/react';
import { useEffect, useRef, useState, type KeyboardEvent } from 'react';
import { useTranslation } from 'react-i18next';
import { controlClass } from '@/Components/Form/Field';

interface AccountOption {
    id: string;
    name: string;
    domain: string | null;
}

interface AccountComboboxProps {
    /** Id-ul contului ales, sau `null` pentru „fără cont" (lead brut, §8.1). */
    value: string | null;
    /**
     * Eticheta contului curent — numele contului la editare, sau la precompletarea
     * `?account=` (US-CRM-01). Necesară pentru că endpoint-ul de căutare nu e interogat
     * doar ca să reafișeze o valoare deja cunoscută la randare.
     */
    initialLabel?: string | null;
    onChange: (id: string | null) => void;
    placeholder?: string;
    /** Integrare cu `Field` — vezi `FieldControlProps`. */
    id: string;
    'aria-describedby'?: string;
    'aria-invalid'?: true;
}

const DEBOUNCE_MS = 200;
const NO_WORKSPACE_BASE = '';

/**
 * P2-001 (code review pachetul „contacte") — selector de cont reutilizabil, în locul
 * unui „Account ID" text liber în care se lipește un ULID (Marlin are ~4.000 de
 * conturi, inutilizabil într-un demo public).
 *
 * Tiparul WAI-ARIA combobox + listbox, scris manual, în stilul `WorkspaceSwitcher`:
 * proiectul nu are (și nu instalează) o librărie de combobox. Cererea de căutare e
 * debounced (~200ms) și anulează cererea anterioară cu `AbortController` — tastare
 * rapidă nu trebuie să lase mai multe cereri concurente în cursă (răspunsul unei
 * cereri vechi ar putea sosi după una nouă).
 *
 * Gândit reutilizabil (și de formularul de deal): nu presupune nimic despre contactul
 * curent, doar `value`/`onChange` pe id-ul contului și o etichetă inițială de afișat.
 */
export default function AccountCombobox({
    id,
    value,
    initialLabel = null,
    onChange,
    placeholder,
    'aria-describedby': ariaDescribedBy,
    'aria-invalid': ariaInvalid,
}: AccountComboboxProps) {
    const { t } = useTranslation('accounts');
    const resolvedPlaceholder = placeholder ?? t('combobox.placeholder');
    const { workspace } = usePage().props;
    const [query, setQuery] = useState('');
    const [selectedLabel, setSelectedLabel] = useState<string | null>(initialLabel);
    const [open, setOpen] = useState(false);
    const [loading, setLoading] = useState(false);
    const [options, setOptions] = useState<AccountOption[]>([]);
    const [activeIndex, setActiveIndex] = useState(-1);
    const inputRef = useRef<HTMLInputElement>(null);
    const abortRef = useRef<AbortController | null>(null);
    const listboxId = `${id}-listbox`;
    const base = workspace ? `/${workspace.slug}` : NO_WORKSPACE_BASE;

    useEffect(() => {
        if (value !== null || query.trim() === '') {
            // Nimic de căutat: fie un cont e deja ales, fie câmpul e gol — golirea
            // rezultatelor pentru cazul gol se face sincron în `onChange`-ul
            // inputului, nu aici (regula `react-hooks/set-state-in-effect`: un efect
            // nu trebuie să apeleze `setState` sincron în corpul lui).
            return;
        }

        const trimmed = query.trim();

        const timer = window.setTimeout(() => {
            abortRef.current?.abort();
            const controller = new AbortController();
            abortRef.current = controller;

            setLoading(true);
            setOpen(true);

            fetch(`${base}/accounts/lookup?q=${encodeURIComponent(trimmed)}`, {
                signal: controller.signal,
                headers: { Accept: 'application/json' },
            })
                .then((response) => {
                    if (!response.ok) {
                        throw new Error('Account lookup failed');
                    }

                    return response.json() as Promise<{ data: AccountOption[] }>;
                })
                .then((body) => {
                    setOptions(body.data);
                    setActiveIndex(body.data.length > 0 ? 0 : -1);
                })
                .catch((error: unknown) => {
                    if (error instanceof DOMException && error.name === 'AbortError') {
                        return;
                    }
                    setOptions([]);
                    setActiveIndex(-1);
                })
                .finally(() => setLoading(false));
        }, DEBOUNCE_MS);

        return () => window.clearTimeout(timer);
    }, [query, value, base]);

    // Anulează orice cerere în zbor la demontare (navigare departe de formular).
    useEffect(() => () => abortRef.current?.abort(), []);

    const selectOption = (option: AccountOption) => {
        setSelectedLabel(option.name);
        setQuery('');
        setOptions([]);
        setOpen(false);
        onChange(option.id);
    };

    const clearSelection = () => {
        setSelectedLabel(null);
        setQuery('');
        setOptions([]);
        onChange(null);
        // Focus revine pe câmpul de căutare, ca utilizatorul să poată alege alt cont
        // fără un click suplimentar.
        requestAnimationFrame(() => inputRef.current?.focus());
    };

    const onKeyDown = (event: KeyboardEvent<HTMLInputElement>) => {
        if (value !== null) {
            return;
        }

        switch (event.key) {
            case 'ArrowDown':
                event.preventDefault();
                if (options.length === 0) {
                    return;
                }
                setOpen(true);
                setActiveIndex((index) => Math.min(index + 1, options.length - 1));
                break;
            case 'ArrowUp':
                event.preventDefault();
                if (options.length === 0) {
                    return;
                }
                setActiveIndex((index) => Math.max(index - 1, 0));
                break;
            case 'Enter': {
                if (!open) {
                    return;
                }
                event.preventDefault();
                const option = options[activeIndex];
                if (option) {
                    selectOption(option);
                }
                break;
            }
            case 'Escape':
                if (open) {
                    event.preventDefault();
                    setOpen(false);
                }
                break;
        }
    };

    return (
        <div className="relative">
            <div className="flex items-center gap-2">
                <input
                    id={id}
                    ref={inputRef}
                    type="text"
                    role="combobox"
                    aria-autocomplete="list"
                    aria-expanded={open}
                    aria-controls={listboxId}
                    aria-activedescendant={open && activeIndex >= 0 ? `${listboxId}-option-${activeIndex}` : undefined}
                    aria-describedby={ariaDescribedBy}
                    aria-invalid={ariaInvalid}
                    readOnly={value !== null}
                    placeholder={resolvedPlaceholder}
                    value={value !== null ? (selectedLabel ?? '') : query}
                    onChange={(event) => {
                        const next = event.target.value;
                        setQuery(next);
                        if (next.trim() === '') {
                            setOptions([]);
                            setOpen(false);
                            setLoading(false);
                        }
                    }}
                    onFocus={() => {
                        if (value === null && options.length > 0) {
                            setOpen(true);
                        }
                    }}
                    onBlur={() => setOpen(false)}
                    onKeyDown={onKeyDown}
                    className={`${controlClass} ${value !== null ? 'cursor-default bg-raised' : ''}`}
                />
                {value !== null && (
                    <button
                        type="button"
                        onClick={clearSelection}
                        className="shrink-0 text-xs text-accent-text underline underline-offset-2 hover:no-underline"
                    >
                        {t('combobox.clear')}
                    </button>
                )}
            </div>

            {open && value === null && (
                <ul
                    id={listboxId}
                    role="listbox"
                    aria-label={t('combobox.listboxLabel')}
                    className="absolute z-20 mt-1 max-h-64 w-full overflow-auto rounded-md border border-border bg-overlay py-1 text-sm shadow-lg"
                >
                    {loading ? (
                        <li aria-disabled="true" className="px-3 py-1.5 text-text-3">
                            {t('combobox.searching')}
                        </li>
                    ) : options.length === 0 ? (
                        <li aria-disabled="true" className="px-3 py-1.5 text-text-3">
                            {t('combobox.noResults')}
                        </li>
                    ) : (
                        options.map((option, index) => (
                            <li
                                key={option.id}
                                id={`${listboxId}-option-${index}`}
                                role="option"
                                aria-selected={index === activeIndex}
                                onMouseDown={(event) => event.preventDefault()}
                                onMouseEnter={() => setActiveIndex(index)}
                                onClick={() => selectOption(option)}
                                className={`cursor-pointer px-3 py-1.5 ${index === activeIndex ? 'bg-row-hover text-text' : 'text-text-2'}`}
                            >
                                {option.name}
                                {option.domain && <span className="text-text-3"> · {option.domain}</span>}
                            </li>
                        ))
                    )}
                </ul>
            )}
        </div>
    );
}
