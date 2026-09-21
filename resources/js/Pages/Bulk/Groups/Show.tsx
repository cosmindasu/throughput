import { Head, router, usePage, usePoll } from '@inertiajs/react';
import { useEffect, useState, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import Button from '@/Components/Button';
import PageHeader from '@/Components/PageHeader';
import StatusBadge, { type BadgeTone } from '@/Components/StatusBadge';
import { useLocale } from '@/hooks/useLocale';
import { formatNumber } from '@/lib/format';
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

const RESOURCE_LABEL_KEYS: Record<string, string> = {
    accounts: 'accounts',
    deals: 'deals',
    orders: 'orders',
};

/**
 * Progresul AGREGAT al unui `group_id` (BR-BULK-04, §13.2) — US-TEN-03 e singurul caz din
 * MVP: reatribuirea la dezactivarea unui membru (sau reatribuirea din vederea
 * „Unassigned") creează un rând `bulk_operations` per tip, legate prin același `groupId`.
 * Mirror-ul de grup al `Bulk/Show.tsx` — același tipar de polling, dar cu o bară pe tip.
 *
 * Val 3 („Lot I18N", ADR-022, lotul A2) — namespace `bulk`. „N record(s) across N type(s)"
 * (tiparele #2 și #3 din raportul lotului — DOUĂ pluralizări independente în ACEEAȘI
 * propoziție) trec pe CLDR real, cu acord de gen/număr francez pe verbul „réparti(s)"
 * legat de numărul de `records` (`bulk:group.description`, `_one`/`_many`/`_other`).
 */
export default function GroupShow() {
    const { group } = usePage<BulkGroupShowPageProps>().props;
    const { t } = useTranslation('bulk');
    const locale = useLocale();
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
    const statusLabel = t(`bulk:status.${group.status}`);
    const liveMessage = isTerminal
        ? t('bulk:group.liveMessageTerminal', { status: statusLabel })
        : t('bulk:group.liveMessageProgress', { status: statusLabel, percent: quantizedPercent });

    const records = t('bulk:group.records', { count: group.totalRows, formatted: formatNumber(group.totalRows, locale) });
    const types = t('bulk:group.types', { count: group.operations.length, formatted: formatNumber(group.operations.length, locale) });

    return (
        <>
            <Head title={t('bulk:group.headTitle')} />

            <div className="flex max-w-2xl flex-col gap-6">
                <PageHeader
                    title={t('bulk:group.pageTitle')}
                    description={t('bulk:group.description', { count: group.totalRows, records, types })}
                />

                <div role="status" className="sr-only">
                    {liveMessage}
                </div>

                <div className="flex flex-col gap-3 rounded-lg border border-border bg-surface p-4">
                    <div className="flex items-center gap-3">
                        <StatusBadge tone={TONES[group.status]}>{statusLabel}</StatusBadge>
                        {!isTerminal && <span className="text-sm text-text-2">{t('bulk:group.updatingNotice')}</span>}
                    </div>

                    <ul className="flex flex-col gap-2">
                        {group.operations.map((operation) => {
                            const percent =
                                operation.totalRows > 0 ? Math.round((operation.processedRowsEstimate / operation.totalRows) * 100) : 0;
                            const resourceLabelKey = RESOURCE_LABEL_KEYS[operation.resourceType];
                            const resourceLabel = resourceLabelKey ? t(`bulk:resourceLabel.${resourceLabelKey}`) : operation.resourceType;

                            return (
                                <li key={operation.id} className="flex flex-col gap-1">
                                    <div className="flex items-center justify-between text-sm">
                                        <span className="font-medium text-text">{resourceLabel}</span>
                                        <span className="numeric text-text-2">
                                            {formatNumber(operation.processedRowsEstimate, locale)} / {formatNumber(operation.totalRows, locale)}
                                        </span>
                                    </div>
                                    <div className="h-1.5 w-full overflow-hidden rounded-full bg-raised" aria-hidden="true">
                                        <div className="h-full bg-accent-fill transition-[width]" style={{ width: `${percent}%` }} />
                                    </div>
                                </li>
                            );
                        })}
                    </ul>

                    {group.status === 'failed' && <span className="text-sm text-danger">{t('bulk:group.failedNotice')}</span>}
                    {group.status === 'cancelled' && <span className="text-sm text-text-2">{t('bulk:group.cancelledNotice')}</span>}
                </div>

                {!isTerminal && group.canCancel && (
                    <div>
                        {/* `aria-disabled`, nu `disabled` nativ, cât cererea de anulare e
                            în curs — la fel ca butoanele din `DeactivateMemberDialog`. */}
                        <Button variant="danger" aria-disabled={cancelling ? true : undefined} onClick={cancel}>
                            {cancelling ? t('bulk:group.cancellingButton') : t('common:actions.cancel')}
                        </Button>
                    </div>
                )}
            </div>
        </>
    );
}

GroupShow.layout = (page: ReactNode) => <AppLayout>{page}</AppLayout>;
