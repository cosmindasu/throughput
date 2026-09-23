import { Head, usePage, usePoll } from '@inertiajs/react';
import { useEffect, useMemo, type ReactNode } from 'react';
import type { TFunction } from 'i18next';
import { useTranslation } from 'react-i18next';
import { buttonClass } from '@/Components/Button';
import PageHeader from '@/Components/PageHeader';
import StatusBadge, { type BadgeTone } from '@/Components/StatusBadge';
import { useLocale } from '@/hooks/useLocale';
import { formatDate, formatNumber } from '@/lib/format';
import AppLayout from '@/Layouts/AppLayout';
import type { ExportsShowPageProps, ExportStatus } from '@/types/generated';

const TERMINAL_STATUSES: ExportStatus[] = ['completed', 'failed', 'cancelled'];

const TONES: Record<ExportStatus, BadgeTone> = {
    pending: 'neutral',
    running: 'accent',
    completed: 'success',
    failed: 'danger',
    cancelled: 'neutral',
};

/**
 * Etichetele de status sunt o FABRICĂ parametrizată pe `t`, nu un `Record` static
 * (`.ai/rules/frontend.md` §6 — „Dacă un tablou de constante la nivel de modul conține
 * etichete... transformă-l într-o fabrică parametrizată și memoizeaz-o"), pe același tipar
 * ca `buildDealColumns`/`buildOrderColumns` din `Pages/Deals|Orders/Index.tsx`.
 */
const buildLabels = (t: TFunction): Record<ExportStatus, string> => ({
    pending: t('imports:exports.show.status.pending'),
    running: t('imports:exports.show.status.running'),
    completed: t('imports:exports.show.status.completed'),
    failed: t('imports:exports.show.status.failed'),
    cancelled: t('imports:exports.show.status.cancelled'),
});

/**
 * Status-ul unui export în coadă (§13.2). Polling la 2 secunde cât operația e
 * `pending`/`running` — Faza 2 nu are WebSockets (specs.md §3, „fără Meilisearch, fără
 * Reverb"). Se oprește singur la starea terminală, ca fila deschisă după download să nu
 * mai bată serverul la nesfârșit.
 *
 * Namespace `imports` (nu unul propriu `exports`) — Val 1 nu a înregistrat un namespace
 * separat pentru export, iar lotul A3 grupează Imports+Exports sub același catalog
 * (`resources/js/locales/{en,fr}/imports.json`, secțiunea `exports.show`).
 */
export default function Show() {
    const { export: exportStatus, workspace } = usePage<ExportsShowPageProps>().props;
    const { t } = useTranslation('imports');
    const locale = useLocale();
    const isTerminal = TERMINAL_STATUSES.includes(exportStatus.status);
    const labels = useMemo(() => buildLabels(t), [t]);

    const { stop } = usePoll(2000, {}, { autoStart: !isTerminal });

    useEffect(() => {
        if (isTerminal) {
            stop();
        }
    }, [isTerminal, stop]);

    return (
        <>
            <Head title={t('imports:exports.show.title')} />

            <div className="flex max-w-xl flex-col gap-6">
                <PageHeader
                    title={t('imports:exports.show.title')}
                    description={t('imports:exports.show.rowsCount', {
                        count: exportStatus.totalRows,
                        formattedCount: formatNumber(exportStatus.totalRows, locale),
                    })}
                />

                <div
                    role="status"
                    aria-live="polite"
                    // Badge-ul și motivul eșecului apar în ACELAȘI commit: citite împreună, ca un
                    // singur enunț („Failed — motivul"), nu fragmentat — ca în `FlashMessages`.
                    aria-atomic="true"
                    className="flex items-center gap-3 rounded-lg border border-border bg-surface p-4"
                >
                    <StatusBadge tone={TONES[exportStatus.status]}>{labels[exportStatus.status]}</StatusBadge>
                    {!isTerminal && <span className="text-sm text-text-2">{t('imports:exports.show.autoUpdate')}</span>}
                    {exportStatus.status === 'failed' && (
                        <span className="text-sm text-danger">
                            {exportStatus.errorMessage ?? t('imports:exports.show.failed')}
                        </span>
                    )}
                    {exportStatus.status === 'completed' && exportStatus.expiresAt && (
                        <span className="text-sm text-text-2">
                            {t(exportStatus.isExpired ? 'imports:exports.show.expired' : 'imports:exports.show.availableUntil', {
                                date: formatDate(exportStatus.expiresAt, locale),
                            })}
                        </span>
                    )}
                </div>

                {exportStatus.canDownload && workspace && (
                    <div>
                        {/* Defect real găsit la auditul E2E (raportul pachetului) — un `<Link>`
                            Inertia (`ButtonLink`) intercepta acest click și trata răspunsul
                            binar (`Content-Disposition: attachment`, fără antet `X-Inertia`)
                            ca o excepție HTTP neașteptată (`handleNonInertiaResponse()` din
                            `@inertiajs/core`): dialogul de eroare al Inertia se deschidea în
                            loc să se declanșeze descărcarea. `<a href>` simplu, EXACT ca
                            exportul sincron din `Orders/Index.tsx` — o navigare reală de
                            browser, nu o vizită Inertia. */}
                        <a href={`/${workspace.slug}/exports/${exportStatus.id}/download`} className={buttonClass('primary')}>
                            {t('imports:exports.show.download', { format: exportStatus.format.toUpperCase() })}
                        </a>
                    </div>
                )}
            </div>
        </>
    );
}

Show.layout = (page: ReactNode) => <AppLayout>{page}</AppLayout>;
