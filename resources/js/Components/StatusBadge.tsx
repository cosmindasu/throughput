import type { ReactNode } from 'react';

export type BadgeTone = 'neutral' | 'accent' | 'success' | 'warning' | 'danger' | 'info';

/**
 * Tentele de chip sunt TOKENS FIXE, nu `color-mix()` peste suprafața curentă (regula din
 * `.ai/rules/frontend.md`): amestecată per context, tenta de eroare cobora de la 4,74:1
 * la 4,17:1 pe `--raised`.
 */
const tones: Record<BadgeTone, string> = {
    neutral: 'bg-raised text-text-2',
    accent: 'bg-accent-tint text-accent-text',
    success: 'bg-success-tint text-success',
    warning: 'bg-warning-tint text-warning',
    danger: 'bg-danger-tint text-danger',
    info: 'bg-info-tint text-info',
};

export default function StatusBadge({ tone = 'neutral', children }: { tone?: BadgeTone; children: ReactNode }) {
    return (
        <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ${tones[tone]}`}>
            {children}
        </span>
    );
}
