import { Head, router, usePage, usePoll } from '@inertiajs/react';
import { useEffect, type ReactNode } from 'react';
import Button from '@/Components/Button';
import PageHeader from '@/Components/PageHeader';
import StatusBadge, { type BadgeTone } from '@/Components/StatusBadge';
import AppLayout from '@/Layouts/AppLayout';
import type { BulkOperationStatus, BulkShowPageProps } from '@/types/generated';

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

const ACTION_LABELS: Record<string, string> = {
    reassign_owner: 'Reassign owner',
    export: 'Export',
    cancel_draft_orders: 'Cancel draft orders',
    update_price: 'Update price',
    set_active: 'Update product status',
};

/**
 * Status-ul unei operații în masă de SCRIERE (§13.2) — mirror-ul lui `Exports/Show.tsx`
 * pentru latura de scriere. Polling la 2 secunde cât operația e `pending`/`running`, fără
 * WebSockets (specs.md §3). Se oprește singur la starea terminală.
 */
export default function Show() {
    const { operation, workspace } = usePage<BulkShowPageProps>().props;
    const isTerminal = TERMINAL_STATUSES.includes(operation.status);

    const { stop } = usePoll(2000, {}, { autoStart: !isTerminal });

    useEffect(() => {
        if (isTerminal) {
            stop();
        }
    }, [isTerminal, stop]);

    const progressPercent = operation.totalRows > 0 ? Math.round((operation.processedRowsEstimate / operation.totalRows) * 100) : 0;

    const cancel = () => {
        if (!workspace) {
            return;
        }

        router.post(`/${workspace.slug}/bulk/${operation.id}/cancel`);
    };

    return (
        <>
            <Head title="Bulk operation" />

            <div className="flex max-w-xl flex-col gap-6">
                <PageHeader
                    title={ACTION_LABELS[operation.action] ?? 'Bulk operation'}
                    description={`${operation.totalRows.toLocaleString('en-US')} ${operation.resourceType}`}
                />

                <div role="status" aria-live="polite" className="flex flex-col gap-3 rounded-lg border border-border bg-surface p-4">
                    <div className="flex items-center gap-3">
                        <StatusBadge tone={TONES[operation.status]}>{LABELS[operation.status]}</StatusBadge>
                        {!isTerminal && <span className="text-sm text-text-2">This page updates automatically.</span>}
                    </div>

                    {!isTerminal && (
                        <div className="h-2 w-full overflow-hidden rounded-full bg-raised" aria-hidden="true">
                            <div className="h-full bg-accent-fill transition-[width]" style={{ width: `${progressPercent}%` }} />
                        </div>
                    )}

                    <p className="numeric text-sm text-text-2">
                        {operation.processedRowsEstimate.toLocaleString('en-US')} / {operation.totalRows.toLocaleString('en-US')} processed
                        {operation.failedJobs > 0 && `, ${operation.failedJobs.toLocaleString('en-US')} chunk(s) failed`}
                    </p>

                    {operation.status === 'failed' && (
                        <span className="text-sm text-danger">
                            {operation.errorMessage ?? 'This operation could not be completed. Try again from the list.'}
                        </span>
                    )}

                    {operation.status === 'cancelled' && (
                        <span className="text-sm text-text-2">Cancelled — rows already processed keep their change, the rest were left unchanged.</span>
                    )}
                </div>

                {!isTerminal && operation.canCancel && (
                    <div>
                        <Button variant="danger" onClick={cancel}>
                            Cancel
                        </Button>
                    </div>
                )}
            </div>
        </>
    );
}

Show.layout = (page: ReactNode) => <AppLayout>{page}</AppLayout>;
