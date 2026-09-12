import type { ReactNode } from 'react';

interface EmptyStateProps {
    message: string;
    action?: ReactNode;
}

/**
 * FR-DEMO-02 — o listă goală spune de ce e goală și ce se poate face, specific
 * contextului: „No deals match this filter. [Clear filters]", nu un ecran alb.
 */
export default function EmptyState({ message, action }: EmptyStateProps) {
    return (
        <div className="flex flex-col items-center gap-3 rounded-lg border border-dashed border-border px-6 py-12 text-center">
            <p className="text-sm text-text-2">{message}</p>
            {action}
        </div>
    );
}
