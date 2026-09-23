import { Head, Link, router, usePage } from '@inertiajs/react';
import type { TFunction } from 'i18next';
import { useState, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import Button, { ButtonLink } from '@/Components/Button';
import ConfirmDialog from '@/Components/ConfirmDialog';
import MoveStageMenu from '@/Components/Deals/MoveStageMenu';
import HistoryTab from '@/Components/History/HistoryTab';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import AppLayout from '@/Layouts/AppLayout';
import { useLocale } from '@/hooks/useLocale';
import { formatDate, formatDateTime } from '@/lib/format';
import { formatMoney } from '@/lib/money';
import type { DealsShowPageProps } from '@/types/generated';

/**
 * Val 3 „Lot I18N" — cele DOUĂ tipare de pluralizare hardcodate semnalate în brief
 * (linia fostă ~23 și ~28) devin chei CLDR (`show.duration.days`/`show.duration.hours`,
 * `_one`/`_other` în en, `_one`/`_many`/`_other` în fr). `t` vine ca parametru — funcția
 * rămâne pură, în afara componentei, ca `buildDealColumns` din `Deals/Index.tsx`.
 */
function formatDuration(t: TFunction, seconds: number | null): string {
    if (seconds === null) {
        return '—';
    }

    const days = Math.floor(seconds / 86400);
    if (days >= 1) {
        return t('show.duration.days', { count: days });
    }

    const hours = Math.floor(seconds / 3600);
    if (hours >= 1) {
        return t('show.duration.hours', { count: hours });
    }

    return t('show.duration.minutes', { count: Math.max(Math.round(seconds / 60), 1) });
}

export default function Show() {
    const { t } = useTranslation('deals');
    const locale = useLocale();
    const { deal, stageEvents, stages, can, workspace } = usePage<DealsShowPageProps>().props;
    const workspaceSlug = workspace?.slug ?? '';
    const [confirmingDelete, setConfirmingDelete] = useState(false);
    const [deleting, setDeleting] = useState(false);
    const [errorMessage, setErrorMessage] = useState<string | null>(null);
    // FE-01 (audit) — dialogul se închide DOAR la succes; la eroare rămâne deschis, cu
    // mesajul afișat în `role="alert"` chiar în el (`.ai/rules/frontend.md`, „Dialogul
    // închis și la eroare”). Stare SEPARATĂ de `errorMessage` de mai sus (mutarea de
    // etapă) — acela se arată pe pagină, ăsta ÎN dialog.
    const [deleteError, setDeleteError] = useState<string | null>(null);

    const destroy = () => {
        setDeleting(true);
        setDeleteError(null);
        router.delete(`/${workspaceSlug}/deals/${deal.id}`, {
            onSuccess: () => setConfirmingDelete(false),
            onError: (errors) => setDeleteError(Object.values(errors)[0] ?? t('show.delete.error')),
            onFinish: () => setDeleting(false),
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
                            {can.edit && <ButtonLink href={`/${workspaceSlug}/deals/${deal.id}/edit`}>{t('show.actions.edit')}</ButtonLink>}
                            {/* Primitiva `Button`, nu clasele variantei `danger` copiate de mână. */}
                            {can.delete && (
                                <Button variant="danger" onClick={() => setConfirmingDelete(true)}>
                                    {t('show.actions.delete')}
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
                    <Field label={t('show.fields.value')} value={<span className="numeric">{formatMoney(deal.value, deal.currency, locale)}</span>} />
                    <Field label={t('show.fields.expectedCloseDate')} value={deal.expectedCloseDate ? formatDate(deal.expectedCloseDate, locale) : '—'} />
                    <Field label={t('show.fields.owner')} value={deal.owner.name} />
                    <Field label={t('show.fields.primaryContact')} value={deal.primaryContact?.name ?? '—'} />
                    <Field label={t('show.fields.pipeline')} value={deal.pipeline.name} />
                    {deal.status === 'lost' && (
                        <Field
                            label={t('show.fields.lostReason')}
                            value={deal.lostReason ? t(`lostReasons.${deal.lostReason}`) : '—'}
                        />
                    )}
                </dl>

                <section aria-label={t('show.stageHistory.heading')} className="rounded-lg border border-border bg-surface p-4">
                    <h2 className="text-sm font-medium text-text-2">{t('show.stageHistory.heading')}</h2>

                    {stageEvents.length === 0 ? (
                        <p className="mt-3 text-sm text-text-2">{t('show.stageHistory.empty')}</p>
                    ) : (
                        <ol className="mt-3 flex flex-col gap-3">
                            {stageEvents.map((event) => (
                                <li key={event.id} className="flex flex-wrap items-baseline justify-between gap-2 border-b border-border-soft pb-2 text-sm last:border-b-0">
                                    <span className="text-text">
                                        {event.fromStage
                                            ? t('show.stageHistory.transition', { from: event.fromStage.name, to: event.toStage.name })
                                            : t('show.stageHistory.createdOn', { stage: event.toStage.name })}
                                    </span>
                                    <span className="text-xs text-text-3">
                                        {event.changedBy?.name ?? t('show.stageHistory.changedBySystem')} · {event.changedAt ? formatDateTime(event.changedAt, locale) : '—'}
                                        {event.fromStage && <> · {t('show.stageHistory.spent', { duration: formatDuration(t, event.durationInPreviousStageSeconds), stage: event.fromStage.name })}</>}
                                    </span>
                                </li>
                            ))}
                        </ol>
                    )}
                </section>

                {/* FR-AUD-02, §17.3 — distinct de „Stage history" de mai sus (evenimente
                    dedicate de pipeline), „History" e strict `activity_log`. */}
                <section aria-label={t('show.history.heading')} className="flex flex-col gap-3">
                    <h2 className="text-sm font-medium text-text">{t('show.history.heading')}</h2>
                    <HistoryTab entityType="deal" entityId={deal.id} />
                </section>
            </div>

            <ConfirmDialog
                open={confirmingDelete}
                title={t('show.delete.title')}
                onClose={() => {
                    setConfirmingDelete(false);
                    setDeleteError(null);
                }}
                onConfirm={destroy}
                confirmLabel={t('show.delete.confirmLabel')}
                confirmVariant="danger"
                processing={deleting}
            >
                {deleteError && (
                    <p role="alert" className="mb-2 rounded-md bg-danger-tint px-2 py-1.5 text-danger">
                        {deleteError}
                    </p>
                )}
                {t('show.delete.body', { title: deal.title })}
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
