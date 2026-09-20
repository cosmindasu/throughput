import { usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import Button from '@/Components/Button';
import EmptyState from '@/Components/EmptyState';
import type { HistoryEntry } from '@/types/generated';

export type HistoryEntityType = 'account' | 'contact' | 'deal' | 'product' | 'variant' | 'order' | 'invoice';

interface HistoryTabProps {
    /** Alias-ul din `App\Support\Activity\AuditableResources` — nu numele complet al clasei. */
    entityType: HistoryEntityType;
    entityId: string;
}

interface HistoryPage {
    data: HistoryEntry[];
    nextCursor: string | null;
}

const dateTimeFormatter = new Intl.DateTimeFormat('en-US', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
});

/**
 * FR-AUD-02, §17.3 — tab „History" reutilizabil, montabil cu o linie pe orice pagină de
 * detaliu: `<HistoryTab entityType="account" entityId={account.id} />`. Nu e un prop
 * Inertia al paginii-gazdă (ca `activity` pe `Accounts/Show`) — componenta își aduce
 * singură datele, prin `fetch()`, exact tiparul din `AccountCombobox`/`AccountLookupController`:
 * un endpoint JSON simplu, nu o navigare Inertia separată, ca tab-ul să nu smulgă
 * utilizatorul de pe pagina entității.
 *
 * Paginare proprie „Load more" (adaugă la coadă), NU `resources/js/Components/
 * CursorPagination.tsx`: acea componentă mută `cursor` în URL-ul PAGINII curente (`usePage().url`)
 * și navighează prin Inertia — corect pentru o listă pe pagina ei proprie (Accounts/Index),
 * greșit aici, unde „Next" ar naviga utilizatorul departe de pagina entității pe care tocmai
 * o citește.
 */
export default function HistoryTab({ entityType, entityId }: HistoryTabProps) {
    const { workspace } = usePage().props;
    const base = workspace ? `/${workspace.slug}` : '';

    const [entries, setEntries] = useState<HistoryEntry[]>([]);
    const [cursor, setCursor] = useState<string | null>(null);
    const [loading, setLoading] = useState(true);
    const [loadingMore, setLoadingMore] = useState(false);
    const [failed, setFailed] = useState(false);

    const load = (afterCursor: string | null, append: boolean) => {
        const url = new URL(`${base}/activity/entity/${entityType}/${entityId}`, window.location.origin);

        if (afterCursor) {
            url.searchParams.set('cursor', afterCursor);
        }

        (append ? setLoadingMore : setLoading)(true);

        fetch(url.toString(), { headers: { Accept: 'application/json' } })
            .then((response) => {
                if (!response.ok) {
                    throw new Error('History fetch failed');
                }

                return response.json() as Promise<HistoryPage>;
            })
            .then((page) => {
                setEntries((previous) => (append ? [...previous, ...page.data] : page.data));
                setCursor(page.nextCursor);
                setFailed(false);
            })
            .catch(() => setFailed(true))
            .finally(() => (append ? setLoadingMore : setLoading)(false));
    };

    useEffect(() => {
        // `react-hooks/set-state-in-effect` (ca în `AccountCombobox`): un `setState`
        // SINCRON în corpul efectului declanșează randări în cascadă — `load()` cheamă
        // `setLoading`/`setLoadingMore` imediat, deci pornește printr-un `setTimeout(…, 0)`,
        // în afara execuției sincrone a efectului, nu direct.
        const timer = window.setTimeout(() => load(null, false), 0);

        return () => window.clearTimeout(timer);
        // Reîncarcă doar când entitatea AFIȘATĂ se schimbă (variantă selectată în altă
        // parte, navigare directă la altă entitate) — `load` recreat la fiecare randare
        // nu trebuie să retrigger-uiască efectul.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [entityType, entityId, base]);

    if (loading) {
        return <p className="text-sm text-text-2">Loading history…</p>;
    }

    if (failed) {
        return <p className="text-sm text-danger">Could not load history.</p>;
    }

    if (entries.length === 0) {
        return <EmptyState message="No changes recorded yet." />;
    }

    return (
        <div className="flex flex-col gap-3">
            <ul className="flex flex-col divide-y divide-border-soft rounded-lg border border-border bg-surface">
                {entries.map((entry) => (
                    <li key={entry.id} className="flex flex-col gap-1.5 px-4 py-3 text-sm">
                        <div className="flex items-center justify-between gap-4">
                            <span className="font-medium text-text">{entry.actionLabel}</span>
                            <time
                                dateTime={entry.createdAt ?? undefined}
                                className="numeric shrink-0 text-xs text-text-3"
                            >
                                {entry.createdAt ? dateTimeFormatter.format(new Date(entry.createdAt)) : ''}
                            </time>
                        </div>
                        <p className="text-xs text-text-3">{entry.actor?.name ?? 'System'}</p>
                        <HistoryValueDiff oldValues={entry.oldValues} newValues={entry.newValues} />
                    </li>
                ))}
            </ul>

            {cursor && (
                <div>
                    <Button variant="secondary" onClick={() => load(cursor, true)} disabled={loadingMore}>
                        {loadingMore ? 'Loading…' : 'Load more'}
                    </Button>
                </div>
            )}
        </div>
    );
}

/**
 * §17.3 — „autor/dată/valoare veche/nouă". Câmpurile redactate (BR-AUD-01) sunt pur și
 * simplu ABSENTE din `oldValues`/`newValues` — nimic aici presupune că toate cheile din
 * `newValues` există și în `oldValues`, sau invers (o creare n-are `oldValues`, o ștergere
 * n-are `newValues`).
 */
function HistoryValueDiff({
    oldValues,
    newValues,
}: {
    oldValues: Record<string, unknown> | null;
    newValues: Record<string, unknown> | null;
}) {
    const fields = Array.from(new Set([...Object.keys(oldValues ?? {}), ...Object.keys(newValues ?? {})])).sort();

    if (fields.length === 0) {
        return null;
    }

    return (
        <dl className="grid grid-cols-1 gap-x-4 gap-y-1 rounded-md bg-raised px-3 py-2 sm:grid-cols-[minmax(0,auto)_1fr_1fr]">
            {fields.map((field) => (
                <div key={field} className="contents">
                    <dt className="text-xs font-medium text-text-2 sm:col-span-1">{field}</dt>
                    <dd className="numeric truncate text-xs text-text-3 line-through decoration-danger/60">
                        {formatValue(oldValues?.[field])}
                    </dd>
                    <dd className="numeric truncate text-xs text-text">{formatValue(newValues?.[field])}</dd>
                </div>
            ))}
        </dl>
    );
}

function formatValue(value: unknown): string {
    if (value === null || value === undefined) {
        return '—';
    }

    if (typeof value === 'object') {
        return JSON.stringify(value);
    }

    return String(value);
}
