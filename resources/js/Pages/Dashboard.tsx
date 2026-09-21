import { Head, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import ActivityFeed from '@/Components/ActivityFeed';
import KpiTile from '@/Components/KpiTile';
import { useLocale } from '@/hooks/useLocale';
import AppLayout from '@/Layouts/AppLayout';
import { formatNumber } from '@/lib/format';
import { type AppLocale } from '@/lib/i18n';
import { formatMoney } from '@/lib/money';
import type { DashboardPageProps } from '@/types/generated';

/**
 * KPI-urile de bani se rotunjesc la unitate — o sumă de pipeline nu se citește cu cenți
 * dintr-o privire. Moneda vine din workspace (specs.md §2.3), nu dintr-o constantă:
 * varianta anterioară avea un formator propriu cu `currency: 'USD'` fixat în cod, care
 * ocolea `formatMoney` și afișa simbolul greșit pe orice tenant care nu e pe USD.
 */
const formatKpiMoney = (value: number, currency: string, locale: AppLocale): string =>
    formatMoney(value, currency, locale, { maximumFractionDigits: 0 });

/**
 * Dashboard-ul de start al unui workspace (FR-DEMO-01, specs.md §21.3) —
 * prima pagină după login. Trebuie să încapă fără scroll pe 1280×800: titlu +
 * 4 KPI tiles + feed de activitate, fără widget-uri suplimentare care nu sunt
 * cerute explicit. Feed-ul lipsește pentru rolurile care nu citesc jurnalul de
 * activitate (Viewer, specs.md §7.4): `activity` vine `null`.
 */
export default function Dashboard() {
    const { workspace, kpis, activity } = usePage<DashboardPageProps>().props;
    const { t } = useTranslation('dashboard');
    const locale = useLocale();
    // Dashboard-ul rulează mereu într-un workspace rezolvat (ruta are `{workspace}`), dar
    // contractul de props îl declară nullabil pentru paginile fără workspace — fallback
    // explicit, nu un cast care să reducă tipul la tăcere.
    const currency = workspace?.currency ?? 'USD';

    return (
        <>
            <Head title={t('dashboard:title')} />

            <div className="flex flex-col gap-6">
                <h1 className="text-xl font-semibold text-text">
                    {workspace?.name}
                    {workspace?.industry && <span className="text-text-2"> — {workspace.industry}</span>}
                </h1>

                <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
                    <KpiTile label={t('dashboard:kpis.openPipelineValue')} value={formatKpiMoney(kpis.openPipelineValue, currency, locale)} />
                    <KpiTile label={t('dashboard:kpis.ordersThisMonth')} value={formatNumber(kpis.ordersThisMonth, locale)} />
                    <KpiTile
                        label={t('dashboard:kpis.overdueInvoices')}
                        value={formatNumber(kpis.overdueInvoices.count, locale)}
                        hint={formatKpiMoney(kpis.overdueInvoices.amount, currency, locale)}
                    />
                    <KpiTile label={t('dashboard:kpis.lowStockAlerts')} value={formatNumber(kpis.lowStockAlerts, locale)} />
                </div>

                {activity !== null && (
                    <section className="rounded-lg border border-border bg-surface p-4" aria-label={t('dashboard:recentActivity')}>
                        <h2 className="text-sm font-medium text-text-2">{t('dashboard:recentActivity')}</h2>
                        <div className="mt-3">
                            <ActivityFeed items={activity} />
                        </div>
                    </section>
                )}
            </div>
        </>
    );
}

Dashboard.layout = (page: ReactNode) => <AppLayout>{page}</AppLayout>;
