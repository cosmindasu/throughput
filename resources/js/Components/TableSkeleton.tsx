import { useTranslation } from 'react-i18next';

interface TableSkeletonProps {
    rows?: number;
    columns?: number;
}

/**
 * Starea de încărcare a unui prop deferred (FR-PERF-01): shell-ul paginii e deja pe
 * ecran, rândurile vin după. Pulsul respectă `prefers-reduced-motion` (`motion-safe:`).
 */
export default function TableSkeleton({ rows = 8, columns = 4 }: TableSkeletonProps) {
    const { t } = useTranslation('common');

    return (
        <div role="status" aria-label={t('common:states.loadingLabel')} className="rounded-lg border border-border bg-surface">
            {Array.from({ length: rows }, (_, row) => (
                <div key={row} className="flex gap-4 border-b border-border-soft px-4 py-3 last:border-b-0">
                    {Array.from({ length: columns }, (_, column) => (
                        <div key={column} className="h-4 flex-1 rounded bg-raised motion-safe:animate-pulse" />
                    ))}
                </div>
            ))}
        </div>
    );
}
