import type { ReactNode } from 'react';
import Icon, { type IconName } from '@/Components/Icon';

interface EmptyStateProps {
    message: string;
    action?: ReactNode;
    /** Implicit `inbox` — „aici ar fi fost rânduri". Apelanții îl pot specializa. */
    icon?: IconName;
}

/**
 * FR-DEMO-02 — o listă goală spune de ce e goală și ce se poate face, specific
 * contextului: „No deals match this filter. [Clear filters]", nu un ecran alb.
 */
export default function EmptyState({ message, action, icon = 'inbox' }: EmptyStateProps) {
    return (
        <div className="flex flex-col items-center gap-3 rounded-lg border border-dashed border-border px-6 py-12 text-center">
            {/* Decorativ (`aria-hidden` în `Icon`): mesajul de dedesubt spune deja de ce e
                goală lista, iar un nume accesibil aici l-ar repeta cu alte cuvinte. */}
            <span className="rounded-full bg-raised p-3 text-text-3">
                <Icon name={icon} size={20} />
            </span>
            <p className="text-sm text-text-2">{message}</p>
            {action}
        </div>
    );
}
