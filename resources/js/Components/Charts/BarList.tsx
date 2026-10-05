import type { CSSProperties } from 'react';

export interface BarListItem {
    id: string;
    label: string;
    /** Valoarea numerică — doar pentru lungimea barei. */
    value: number;
    /** Valoarea afișată, deja formatată (monedă, număr). */
    display: string;
    /** Rând secundar, ex. „12 deals · 30%". */
    hint?: string;
    /** Valoare CSS: `var(--stage-2)`, `var(--success)` … niciodată hex literal. */
    color: string;
}

interface BarListProps {
    items: BarListItem[];
    /** Numele accesibil al listei. */
    label: string;
}

/**
 * Bare orizontale în HTML/CSS, nu în SVG: textul rămâne text (se redimensionează cu zoom-ul,
 * se citește natural), iar „alternativa textuală" e chiar lista — etichetă, valoare și hint
 * sunt în DOM, bara e decor (`aria-hidden`). Direct-labeling înseamnă că nicio informație nu
 * depinde de culoare (SC 1.4.1).
 */
export default function BarList({ items, label }: BarListProps) {
    const max = Math.max(...items.map((item) => item.value), 1);

    return (
        <ul aria-label={label} className="flex flex-col gap-3.5">
            {items.map((item, index) => (
                <li key={item.id} className="flex flex-col gap-1.5" style={{ '--i': index } as CSSProperties}>
                    <div className="flex items-baseline justify-between gap-3 text-sm">
                        <span className="truncate text-text-2">{item.label}</span>
                        <span className="numeric shrink-0 text-text">{item.display}</span>
                    </div>
                    <span aria-hidden="true" className="block h-2 overflow-hidden rounded-full bg-raised ring-1 ring-inset ring-border-soft">
                        <span
                            className="block h-full origin-left rounded-full motion-safe:animate-grow"
                            style={{ width: `${Math.max((item.value / max) * 100, item.value > 0 ? 2 : 0)}%`, backgroundColor: item.color, animationDelay: 'calc(var(--i) * 60ms)' }}
                        />
                    </span>
                    {item.hint && <span className="text-xs text-text-3">{item.hint}</span>}
                </li>
            ))}
        </ul>
    );
}
