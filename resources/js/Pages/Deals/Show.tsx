import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';
import Button, { ButtonLink } from '@/Components/Button';
import ConfirmDialog from '@/Components/ConfirmDialog';
import MoveStageMenu from '@/Components/Deals/MoveStageMenu';
import HistoryTab from '@/Components/History/HistoryTab';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import AppLayout from '@/Layouts/AppLayout';
import { formatMoney } from '@/lib/money';
import type { DealsShowPageProps } from '@/types/generated';

const dateFormatter = new Intl.DateTimeFormat('en-US', { dateStyle: 'medium' });
const dateTimeFormatter = new Intl.DateTimeFormat('en-US', { dateStyle: 'medium', timeStyle: 'short' });

function formatDuration(seconds: number | null): string {
    if (seconds === null) {
        return '—';
    }

    const days = Math.floor(seconds / 86400);
    if (days >= 1) {
        return `${days} day${days === 1 ? '' : 's'}`;
    }

    const hours = Math.floor(seconds / 3600);
    if (hours >= 1) {
        return `${hours} hour${hours === 1 ? '' : 's'}`;
    }

    return `${Math.max(Math.round(seconds / 60), 1)} min`;
}

export default function Show() {
    const { deal, stageEvents, stages, can, workspace } = usePage<DealsShowPageProps>().props;
    const workspaceSlug = workspace?.slug ?? '';
    const [confirmingDelete, setConfirmingDelete] = useState(false);
    const [deleting, setDeleting] = useState(false);
    const [errorMessage, setErrorMessage] = useState<string | null>(null);

    const destroy = () => {
        setDeleting(true);
        router.delete(`/${workspaceSlug}/deals/${deal.id}`, {
            onFinish: () => {
                setDeleting(false);
                setConfirmingDelete(false);
            },
        });
    };

    return (
        <>
            <Head title={deal.title} />

            <div className="flex flex-col gap-6">
                <PageHeader
                    title={deal.title}
                    description={
                        <span className="flex flex-wrap items-center gap-2">
                            <Link href={`/${workspaceSlug}/accounts/${deal.account.id}`} className="hover:underline">
                                {deal.account.name}
                            </Link>
                            <StatusBadge tone={deal.status === 'won' ? 'success' : deal.status === 'lost' ? 'danger' : 'neutral'}>
                                {deal.stage.name}
                            </StatusBadge>
                        </span>
                    }
                    actions={
                        <>
                            {can.moveStage && (
                                <MoveStageMenu
                                    workspaceSlug={workspaceSlug}
                                    dealId={deal.id}
                                    currentStageId={deal.stage.id}
                                    stages={stages}
                                    onError={setErrorMessage}
                                />
                            )}
                            {can.edit && <ButtonLink href={`/${workspaceSlug}/deals/${deal.id}/edit`}>Edit</ButtonLink>}
                            {/* Primitiva `Button`, nu clasele variantei `danger` copiate de mână. */}
                            {can.delete && (
                                <Button variant="danger" onClick={() => setConfirmingDelete(true)}>
                                    Delete
                                </Button>
                            )}
                        </>
                    }
                />

                {errorMessage && (
                    <p role="alert" className="rounded-md bg-danger-tint px-3 py-2 text-sm text-danger">
                        {errorMessage}
                    </p>
                )}

                <dl className="grid gap-4 rounded-lg border border-border bg-surface p-4 sm:grid-cols-2 lg:grid-cols-3">
                    <Field label="Value" value={<span className="numeric">{formatMoney(deal.value, deal.currency)}</span>} />
                    <Field label="Expected close date" value={deal.expectedCloseDate ? dateFormatter.format(new Date(deal.expectedCloseDate)) : '—'} />
                    <Field label="Owner" value={deal.owner.name} />
                    <Field label="Primary contact" value={deal.primaryContact?.name ?? '—'} />
                    <Field label="Pipeline" value={deal.pipeline.name} />
                    {deal.status === 'lost' && <Field label="Lost reason" value={deal.lostReason ?? '—'} />}
                </dl>

                <section aria-label="Stage history" className="rounded-lg border border-border bg-surface p-4">
                    <h2 className="text-sm font-medium text-text-2">Stage history</h2>

                    {stageEvents.length === 0 ? (
                        <p className="mt-3 text-sm text-text-2">No stage changes yet.</p>
                    ) : (
                        <ol className="mt-3 flex flex-col gap-3">
                            {stageEvents.map((event) => (
                                <li key={event.id} className="flex flex-wrap items-baseline justify-between gap-2 border-b border-border-soft pb-2 text-sm last:border-b-0">
                                    <span className="text-text">
                                        {event.fromStage ? `${event.fromStage.name} → ${event.toStage.name}` : `Created on ${event.toStage.name}`}
                                    </span>
                                    <span className="text-xs text-text-3">
                                        {event.changedBy?.name ?? 'System'} · {event.changedAt ? dateTimeFormatter.format(new Date(event.changedAt)) : '—'}
                                        {event.fromStage && <> · spent {formatDuration(event.durationInPreviousStageSeconds)} on {event.fromStage.name}</>}
                                    </span>
                                </li>
                            ))}
                        </ol>
                    )}
                </section>

                {/* FR-AUD-02, §17.3 — distinct de „Stage history" de mai sus (evenimente
                    dedicate de pipeline), „History" e strict `activity_log`. */}
                <section aria-label="History" className="flex flex-col gap-3">
                    <h2 className="text-sm font-medium text-text">History</h2>
                    <HistoryTab entityType="deal" entityId={deal.id} />
                </section>
            </div>

            <ConfirmDialog
                open={confirmingDelete}
                title="Delete this deal?"
                onClose={() => setConfirmingDelete(false)}
                onConfirm={destroy}
                confirmLabel="Delete"
                confirmVariant="danger"
                processing={deleting}
            >
                This removes “{deal.title}” and its stage history. This cannot be undone.
            </ConfirmDialog>
        </>
    );
}

function Field({ label, value }: { label: string; value: ReactNode }) {
    return (
        <div>
            <dt className="text-xs font-medium text-text-3">{label}</dt>
            <dd className="mt-0.5 text-sm text-text">{value}</dd>
        </div>
    );
}

Show.layout = (page: ReactNode) => <AppLayout>{page}</AppLayout>;
