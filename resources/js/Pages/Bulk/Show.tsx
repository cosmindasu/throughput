import { Head, router, usePage, usePoll } from '@inertiajs/react';
import { useEffect, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import Button from '@/Components/Button';
import PageHeader from '@/Components/PageHeader';
import StatusBadge, { type BadgeTone } from '@/Components/StatusBadge';
import { useLocale } from '@/hooks/useLocale';
import { formatNumber } from '@/lib/format';
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

/**
 * Aceeași hartă ca pe pagina soră `Bulk/Groups/Show.tsx` — fără ea, descrierea afișa
 * identificatorul brut din backend („1,842 accounts", cu literă mică, direct din
 * `resourceType`), în timp ce pagina de grup afișa „Accounts". Găsit la un audit
 * încrucișat, în Faza 5.
 *
 * `products` LIPSEȘTE aici la fel ca înainte de acest lot — `ACTION_LABELS` de mai jos
 * ARE `update_price`/`set_active` (acțiuni pe produse), deci un `operation.resourceType`
 * de `'products'` tot cade pe fallback-ul `?? operation.resourceType` (rândul brut,
 * netradus). Comportament PĂSTRAT identic (nu era în lista celor 11 tipare semnalate de
 * brief) — vezi raportul lotului A2, pct. 6, pentru semnalarea explicită.
 */
const RESOURCE_LABEL_KEYS: Record<string, string> = {
    accounts: 'accounts',
    deals: 'deals',
    orders: 'orders',
};

const ACTION_LABEL_KEYS: Record<string, string> = {
    reassign_owner: 'reassignOwner',
    export: 'export',
    cancel_draft_orders: 'cancelDraftOrders',
    update_price: 'updatePrice',
    set_active: 'setActive',
};

/**
 * Status-ul unei operații în masă de SCRIERE (§13.2) — mirror-ul lui `Exports/Show.tsx`
 * pentru latura de scriere. Polling la 2 secunde cât operația e `pending`/`running`, fără
 * WebSockets (specs.md §3). Se oprește singur la starea terminală.
 *
 * Val 3 („Lot I18N", ADR-022, lotul A2) — namespace `bulk`. Trei `toLocaleString('en-US')`
 * migrate la `formatNumber(…, locale)`; „N chunk(s) failed" (tiparul #4 din raportul
 * lotului) trece pe pluralizare CLDR reală (`bulk:show.chunksFailed`).
 */
export default function Show() {
    const { operation, workspace } = usePage<BulkShowPageProps>().props;
    const { t } = useTranslation('bulk');
    const locale = useLocale();
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

    const resourceLabelKey = RESOURCE_LABEL_KEYS[operation.resourceType];
    const resourceLabel = resourceLabelKey ? t(`bulk:resourceLabel.${resourceLabelKey}`) : operation.resourceType;
    const actionLabelKey = ACTION_LABEL_KEYS[operation.action];
    const pageTitle = actionLabelKey ? t(`bulk:action.${actionLabelKey}`) : t('bulk:show.fallbackActionLabel');

    return (
        <>
            <Head title={t('bulk:show.headTitle')} />

            <div className="flex max-w-xl flex-col gap-6">
                <PageHeader
                    title={pageTitle}
                    description={t('bulk:show.description', { count: formatNumber(operation.totalRows, locale), resource: resourceLabel })}
                />

                <div role="status" aria-live="polite" className="flex flex-col gap-3 rounded-lg border border-border bg-surface p-4">
                    <div className="flex items-center gap-3">
                        <StatusBadge tone={TONES[operation.status]}>{t(`bulk:status.${operation.status}`)}</StatusBadge>
                        {!isTerminal && <span className="text-sm text-text-2">{t('bulk:show.updatingNotice')}</span>}
                    </div>

                    {!isTerminal && (
                        <div className="h-2 w-full overflow-hidden rounded-full bg-raised" aria-hidden="true">
                            <div className="h-full bg-accent-fill transition-[width]" style={{ width: `${progressPercent}%` }} />
                        </div>
                    )}

                    <p className="numeric text-sm text-text-2">
                        {t('bulk:show.processed', {
                            count: operation.totalRows,
                            processed: formatNumber(operation.processedRowsEstimate, locale),
                            total: formatNumber(operation.totalRows, locale),
                        })}
                        {operation.failedJobs > 0 &&
                            `, ${t('bulk:show.chunksFailed', { count: operation.failedJobs, formatted: formatNumber(operation.failedJobs, locale) })}`}
                    </p>

                    {operation.status === 'failed' && (
                        <span className="text-sm text-danger">{operation.errorMessage ?? t('bulk:show.errorFallback')}</span>
                    )}

                    {operation.status === 'cancelled' && <span className="text-sm text-text-2">{t('bulk:show.cancelledNotice')}</span>}

                    {/* US-BULK-01, §13.3 (lotul E) — visible only once rows may actually exist to show. */}
                    {isTerminal && operation.activityLogUrl && (
                        <a href={operation.activityLogUrl} className="text-sm text-accent-text hover:underline">
                            {t('bulk:show.activityLogLink')}
                        </a>
                    )}
                </div>

                {!isTerminal && operation.canCancel && (
                    <div>
                        <Button variant="danger" onClick={cancel}>
                            {t('common:actions.cancel')}
                        </Button>
                    </div>
                )}
            </div>
        </>
    );
}

Show.layout = (page: ReactNode) => <AppLayout>{page}</AppLayout>;
