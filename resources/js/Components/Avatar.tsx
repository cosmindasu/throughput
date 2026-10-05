/**
 * Șase perechi tentă/text, LITERALE (Tailwind nu generează `bg-series-${n}-tint`). Contrast
 * text-pe-tentă măsurat: ≥ 5,30:1 pe tema deschisă, ≥ 5,86:1 pe cea închisă.
 */
const TONES = [
    'bg-series-1-tint text-series-1-text',
    'bg-series-2-tint text-series-2-text',
    'bg-series-3-tint text-series-3-text',
    'bg-series-4-tint text-series-4-text',
    'bg-series-5-tint text-series-5-text',
    'bg-series-6-tint text-series-6-text',
] as const;

export function initialsOf(name: string): string {
    // „Marcus Reyes (deactivated)" — sufixul de placeholder (FR-TEN-04) nu face parte din inițiale.
    const parts = name.replace(/\s*\(.*?\)\s*/g, ' ').trim().split(/\s+/).filter(Boolean);

    if (parts.length === 0) {
        return '?';
    }

    const first = Array.from(parts[0])[0] ?? '';
    const last = parts.length > 1 ? (Array.from(parts[parts.length - 1])[0] ?? '') : '';

    return (first + last).toLocaleUpperCase();
}

/** Același id → aceeași culoare, în orice sesiune și pe orice pagină (identitate, nu decor). */
function toneIndex(id: string): number {
    let hash = 0;

    for (let i = 0; i < id.length; i++) {
        hash = (hash * 31 + id.charCodeAt(i)) >>> 0;
    }

    return hash % TONES.length;
}

interface AvatarProps {
    id: string;
    name: string;
    size?: 24 | 28 | 32;
}

/**
 * `aria-hidden`: numele persoanei apare lângă el ca text (sau ca `sr-only`), deci avatarul e
 * redundant pentru tehnologiile asistive — aceeași regulă ca `Icon` și `ToneIcon`.
 */
export default function Avatar({ id, name, size = 24 }: AvatarProps) {
    return (
        <span
            aria-hidden="true"
            title={name}
            className={`inline-flex shrink-0 items-center justify-center rounded-full font-medium ${TONES[toneIndex(id)]}`}
            style={{ width: size, height: size, fontSize: Math.max(11, Math.round(size * 0.42)) }}
        >
            {initialsOf(name)}
        </span>
    );
}
