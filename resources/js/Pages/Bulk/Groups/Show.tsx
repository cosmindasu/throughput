import { Head, router, usePage, usePoll } from '@inertiajs/react';
import { useEffect, useState, type ReactNode } from 'react';
import Button from '@/Components/Button';
import PageHeader from '@/Components/PageHeader';
import StatusBadge, { type BadgeTone } from '@/Components/StatusBadge';
import AppLayout from '@/Layouts/AppLayout';
import type { BulkGroupShowPageProps, BulkOperationStatus } from '@/types/generated';

const TERMINAL_STATUSES: BulkOperationStatus[] = ['completed', 'failed', 'cancelled'];

const TONES: Record<BulkOperationStatus, BadgeTone> = {
    pending: 'neutral',
    running: 'accent',
    completed: 'success',
    failed: 'danger',
    cancelled: 'neutral',
};

const LABELS: Record<BulkOperationStatus, string> = {
    pending: 'Queued',
    running: 'Running',
    completed: 'Done',
    failed: 'Failed',
    cancelled: 'Cancelled',
};

const RESOURCE_LABELS: Record<string, string> = {
    accounts: 'Accounts',
    deals: 'Deals',
    orders: 'Orders',
};

/**
 * Progresul AGREGAT al unui `group_id` (BR-BULK-04, §13.2) — US-TEN-03 e singurul caz din
 * MVP: reatribuirea la dezactivarea unui membru (sau reatribuirea din vederea
 * „Unassigned") creează un rând `bulk_operations` per tip, legate prin același `groupId`.
 * Mirror-ul de grup al `Bulk/Show.tsx` — același tipar de polling, dar cu o bară pe tip.
 */
export default function GroupShow() {
    const { group } = usePage<BulkGroupShowPageProps>().props;
    const isTerminal = TERMINAL_STATUSES.includes(group.status);
    const [cancelling, setCancelling] = useState(false);

    const { stop } = usePoll(2000, {}, { autoStart: !isTerminal });

    useEffect(() => {
        if (isTerminal) {
            stop();
        }
    }, [isTerminal, stop]);

    const cancel = () => {
        if (cancelling) {
            return;
        }

        setCancelling(true);
        router.post(`/bulk/groups/${group.groupId}/cancel`, {}, { onFinish: () => setCancelling(false) });
    };

    // Audit de accesibilitate (P1, pct. 6) — regiune SEPARATĂ, mică, doar pentru
    // anunțuri: containerul vizual de mai jos (bara + lista per tip) NU mai are
    // `aria-live` — cu poll la 2 s, un `aria-live` pe tot conținutul ar anunța continuu
    // orice tremur de o cifră. Anunțăm doar schimbarea de stare și progresul rotunjit la
    // trepte de 25%, ca șirul redat efectiv să se schimbe rar.
    const totalProcessed = group.operations.reduce((sum, operation) => sum + operation.processedRowsEstimate, 0);
    const overallPercent = group.totalRows > 0 ? Math.round((totalProcessed / group.totalRows) * 100) : 0;
    const quantizedPercent = Math.min(100, Math.floor(overallPercent / 25) * 25);
    const liveMessage = isTerminal ? `${LABELS[group.status]}.` : `${LABELS[group.status]} — about ${quantizedPercent}% complete.`;

    return (
        <>
            <Head title="Bulk operation" />

            <div className="flex max-w-2xl flex-col gap-6">
                <PageHeader
                    title="Reassigning records"
                    description={`${group.totalRows.toLocaleString('en-US')} record(s) across ${group.operations.length} type(s)`}
                />

                <div role="status" className="sr-only">
                    {liveMessage}
                </div>

                <div className="flex flex-col gap-3 rounded-lg border border-border bg-surface p-4">
                    <div className="flex items-center gap-3">
                        <StatusBadge tone={TONES[group.status]}>{LABELS[group.status]}</StatusBadge>
                        {!isTerminal && <span className="text-sm text-text-2">This page updates automatically.</span>}
                    </div>

                    <ul className="flex flex-col gap-2">
                        {group.operations.map((operation) => {
                            const percent =
                                operation.totalRows > 0 ? Math.round((operation.processedRowsEstimate / operation.totalRows) * 100) : 0;

                            return (
                                <li key={operation.id} className="flex flex-col gap-1">
                                    <div className="flex items-center justify-between text-sm">
                                        <span className="font-medium text-text">
                                            {RESOURCE_LABELS[operation.resourceType] ?? operation.resourceType}
                                        </span>
                                        <span className="numeric text-text-2">
                                            {operation.processedRowsEstimate.toLocaleString('en-US')} / {operation.totalRows.toLocaleString('en-US')}
                                        </span>
                                    </div>
                                    <div className="h-1.5 w-full overflow-hidden rounded-full bg-raised" aria-hidden="true">
                                        <div className="h-full bg-accent-fill transition-[width]" style={{ width: `${percent}%` }} />
                                    </div>
                                </li>
                            );
                        })}
                    </ul>

                    {group.status === 'failed' && (
                        <span className="text-sm text-danger">One or more record types could not be reassigned. Retry from the list.</span>
                    )}
                    {group.status === 'cancelled' && (
                        <span className="text-sm text-text-2">Cancelled — records already reassigned keep their new owner.</span>
                    )}
                </div>

                {!isTerminal && group.canCancel && (
                    <div>
                        {/* `aria-disabled`, nu `disabled` nativ, cât cererea de anulare e
                            în curs — la fel ca butoanele din `DeactivateMemberDialog`. */}
                        <Button variant="danger" aria-disabled={cancelling ? true : undefined} onClick={cancel}>
                            {cancelling ? 'Cancelling…' : 'Cancel'}
                        </Button>
                    </div>
                )}
            </div>
        </>
    );
}

GroupShow.layout = (page: ReactNode) => <AppLayout>{page}</AppLayout>;
