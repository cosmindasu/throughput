import { usePage } from '@inertiajs/react';
import { useEffect, useRef, useState, type KeyboardEvent } from 'react';
import { controlClass } from '@/Components/Form/Field';
import type { OrderVariantOption } from '@/types/generated';

interface VariantComboboxProps {
    onSelect: (variant: OrderVariantOption) => void;
    placeholder?: string;
}

const DEBOUNCE_MS = 200;

/**
 * Selector de variantă pentru `OrderLinesEditor` — același tipar WAI-ARIA combobox +
 * listbox ca `AccountCombobox` (P2-001), dar fără o „valoare aleasă" persistentă: la
 * fiecare selecție adaugă o linie nouă și se golește, ca să poți căuta imediat
 * următoarea variantă (US-ORD-01 — „adaug 2 linii cu variante și cantități").
 */
export default function VariantCombobox({ onSelect, placeholder = 'Search by SKU or product name…' }: VariantComboboxProps) {
    const { workspace } = usePage().props;
    const [query, setQuery] = useState('');
    const [open, setOpen] = useState(false);
    const [loading, setLoading] = useState(false);
    const [options, setOptions] = useState<OrderVariantOption[]>([]);
    const [activeIndex, setActiveIndex] = useState(-1);
    const inputRef = useRef<HTMLInputElement>(null);
    const abortRef = useRef<AbortController | null>(null);
    const base = workspace ? `/${workspace.slug}` : '';

    useEffect(() => {
        const trimmed = query.trim();

        if (trimmed === '') {
            // Golirea pentru cazul gol se face SINCRON în `onChange`-ul inputului, nu
            // aici (regula `react-hooks/set-state-in-effect`, la fel ca `AccountCombobox`):
            // un efect nu trebuie să apeleze `setState` sincron în corpul lui.
            return;
        }

        const timer = window.setTimeout(() => {
            abortRef.current?.abort();
            const controller = new AbortController();
            abortRef.current = controller;

            setLoading(true);
            setOpen(true);

            fetch(`${base}/orders/variants/lookup?q=${encodeURIComponent(trimmed)}`, {
                signal: controller.signal,
                headers: { Accept: 'application/json' },
            })
                .then((response) => {
                    if (!response.ok) {
                        throw new Error('Variant lookup failed');
                    }

                    return response.json() as Promise<{ data: OrderVariantOption[] }>;
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
    }, [query, base]);

    useEffect(() => () => abortRef.current?.abort(), []);

    const selectOption = (option: OrderVariantOption) => {
        onSelect(option);
        setQuery('');
        setOptions([]);
        setOpen(false);
        requestAnimationFrame(() => inputRef.current?.focus());
    };

    const onKeyDown = (event: KeyboardEvent<HTMLInputElement>) => {
        switch (event.key) {
            case 'ArrowDown':
                event.preventDefault();
                if (options.length > 0) {
                    setOpen(true);
                    setActiveIndex((index) => Math.min(index + 1, options.length - 1));
                }
                break;
            case 'ArrowUp':
                event.preventDefault();
                if (options.length > 0) {
                    setActiveIndex((index) => Math.max(index - 1, 0));
                }
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

    const listboxId = 'order-variant-listbox';

    return (
        <div className="relative">
            <input
                ref={inputRef}
                type="text"
                role="combobox"
                aria-autocomplete="list"
                aria-expanded={open}
                aria-controls={listboxId}
                aria-label="Add a line"
                placeholder={placeholder}
                value={query}
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
                    if (options.length > 0) {
                        setOpen(true);
                    }
                }}
                onBlur={() => setOpen(false)}
                onKeyDown={onKeyDown}
                className={controlClass}
            />

            {open && (
                <ul
                    id={listboxId}
                    role="listbox"
                    aria-label="Variants"
                    className="absolute z-20 mt-1 max-h-64 w-full overflow-auto rounded-md border border-border bg-overlay py-1 text-sm shadow-lg"
                >
                    {loading ? (
                        <li aria-disabled="true" className="px-3 py-1.5 text-text-3">
                            Searching…
                        </li>
                    ) : options.length === 0 ? (
                        <li aria-disabled="true" className="px-3 py-1.5 text-text-3">
                            No variants found.
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
                                className={`flex cursor-pointer items-center justify-between gap-2 px-3 py-1.5 ${index === activeIndex ? 'bg-row-hover text-text' : 'text-text-2'}`}
                            >
                                <span>
                                    {option.name} <span className="text-text-3">· {option.sku}</span>
                                </span>
                                <span className="numeric shrink-0 text-xs text-text-3">Available: {option.available}</span>
                            </li>
                        ))
                    )}
                </ul>
            )}
        </div>
    );
}
