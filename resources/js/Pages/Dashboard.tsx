import { Head, Link, usePage } from '@inertiajs/react';
import { type ReactNode, useMemo } from 'react';
import { useTranslation } from 'react-i18next';
import ActivityFeed from '@/Components/ActivityFeed';
import AttentionList from '@/Components/AttentionList';
import { ButtonLink } from '@/Components/Button';
import AreaChart from '@/Components/Charts/AreaChart';
import BarList, { type BarListItem } from '@/Components/Charts/BarList';
import ChartSkeleton from '@/Components/Charts/ChartSkeleton';
import Donut from '@/Components/Charts/Donut';
import DeferredData from '@/Components/DeferredData';
import Icon from '@/Components/Icon';
import KpiTile from '@/Components/KpiTile';
import Panel from '@/Components/Panel';
import { useLocale } from '@/hooks/useLocale';
import AppLayout from '@/Layouts/AppLayout';
import { formatNumber, getNumberFormat } from '@/lib/format';
import { type AppLocale } from '@/lib/i18n';
import { formatMoney } from '@/lib/money';
import { stageColors } from '@/lib/stageColor';
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
 * Culorile feliilor vin din ACEEAȘI semantică de status ca insignele din `Orders/Index`: o
 * comandă „fulfilled" e verde și în listă, și în grafic. Valorile sunt tokeni (`var(--…)`),
 * deci urmează tema — regula din `.ai/rules/frontend.md`.
 */
const ORDER_STATUS_COLOR: Record<string, string> = {
    draft: 'var(--control)',
    confirmed: 'var(--accent-fill)',
    partially_fulfilled: 'var(--info)',
    fulfilled: 'var(--success)',
    cancelled: 'var(--danger)',
};

/** `partially_fulfilled` (enum PHP) → `partiallyFulfilled` (cheie i18next, camelCase). */
const statusKey = (status: string): string => status.replace(/_(.)/g, (_, char: string) => char.toUpperCase());

/**
 * Dashboard-ul de start al unui workspace (FR-DEMO-01, specs.md §21.3) — prima pagină după
 * login.
 *
 * **Nu mai încape într-un ecran, deliberat.** Versiunea anterioară era construită în jurul
 * constrângerii „titlu + 4 plăci + feed, fără widget-uri suplimentare, fără scroll pe
 * 1280×800". Constrângerea a fost ridicată explicit (decizie de produs, 2026-10-05) fiindcă
 * producea exact efectul invers celui dorit: patru cifre și o listă de evenimente nu arată
 * ce FACE produsul, iar un demo pe care clientul îl deschide prima oară trebuie să-l invite
 * să lucreze în el, nu doar să-l informeze. Ce a crescut nu e decor — fiecare bloc nou
 * răspunde la o întrebare pe care cele patru cifre o lăsau deschisă: cum evoluează
 * (grafic de 12 luni), unde stau afacerile (pipeline pe etape), ce fel de comenzi sunt
 * (donut pe status) și CARE anume cer acțiune (`AttentionList` — plăcile spuneau doar cât).
 *
 * **Ce rămâne neatins și de ce:** grila de KPI-uri (`.grid.grid-cols-2` → `lg:grid-cols-4`,
 * patru `div` copii direcți) e selectată literal de `e2e/specs/i18n-layout.spec.ts`, care
 * verifică alinierea celor patru valori pe rând în EN și FR. Plăcile au devenit clicabile
 * fără să-și schimbe rădăcina (vezi docblock-ul din `KpiTile`), iar niciun bloc nou nu
 * folosește clasa `grid-cols-2`, ca selectorul să rămână unic pe pagină.
 *
 * Feed-ul lipsește pentru rolurile care nu citesc jurnalul de activitate (Viewer,
 * specs.md §7.4): `activity` vine `null`. Graficele și lista de urgențe sunt AMÂNATE
 * (`Inertia::defer`), deci pagina se vede înainte ca agregările să se termine.
 */
export default function Dashboard() {
    const { workspace, kpis, activity, auth } = usePage<DashboardPageProps>().props;
    const { t } = useTranslation(['dashboard', 'orders']);
    const locale = useLocale();
    // Dashboard-ul rulează mereu într-un workspace rezolvat (ruta are `{workspace}`), dar
    // contractul de props îl declară nullabil pentru paginile fără workspace — fallback
    // explicit, nu un cast care să reducă tipul la tăcere.
    const currency = workspace?.currency ?? 'USD';
    const base = workspace ? `/${workspace.slug}` : '';

    const money = useMemo(() => (value: number) => formatKpiMoney(value, currency, locale), [currency, locale]);
    // Axa Y a graficului: „1,2 mil." în loc de „1.200.000" — altfel eticheta de pe axă e mai
    // lată decât marginea rezervată pentru ea.
    const compact = useMemo(
        () => (value: number) => getNumberFormat(locale, { notation: 'compact', maximumFractionDigits: 1 }).format(value),
        [locale],
    );
    const percent = useMemo(
        () => (ratio: number) =>
            getNumberFormat(locale, { style: 'percent', signDisplay: 'exceptZero', maximumFractionDigits: 0 }).format(ratio),
        [locale],
    );
    const count = useMemo(() => (value: number) => formatNumber(value, locale), [locale]);
    const monthFormat = useMemo(() => new Intl.DateTimeFormat(locale, { month: 'short' }), [locale]);

    return (
        <>
            <Head title={t('dashboard:title')} />

            <div className="flex flex-col gap-6">
                <div className="flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <h1 className="text-xl font-semibold text-text">
                            {workspace?.name}
                            {workspace?.industry && <span className="text-text-2"> — {workspace.industry}</span>}
                        </h1>
                        {/*
                            Prenumele, nu numele întreg: un dashboard se adresează persoanei care
                            l-a deschis. `split(' ')[0]` e suficient aici fiindcă singurul rezultat
                            posibil al unui nume fără spațiu e numele însuși.
                        */}
                        <p className="mt-1 text-sm text-text-2">{t('dashboard:greeting', { name: auth.user?.name.split(' ')[0] ?? '' })}</p>
                    </div>
                    <ButtonLink href={`${base}/deals/create`} variant="primary">
                        <Icon name="plus" size={16} />
                        {t('dashboard:quickActions.newDeal')}
                    </ButtonLink>
                </div>

                {/*
                    Tenta celor două plăci de abatere se citește DIN VALOARE, nu e fixată:
                    zero facturi restante e o veste bună și arată ca atare (`success`), nu ca
                    o alarmă roșie permanentă. Primele două plăci raportează volum, nu o
                    abatere, deci rămân pe tente neutre-de-brand (accent/info) indiferent de
                    cifră — nu există prag de la care „multe comenzi" ar fi o problemă.

                    Linia și procentul apar DOAR pe placa de comenzi, singura cu o serie reală
                    în spate (vezi `KpiTile.trend`). Celelalte trei raportează un instantaneu;
                    o linie compusă din altă serie ar arăta ca o evoluție fără să fie una.
                */}
                <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
                    <KpiTile
                        label={t('dashboard:kpis.openPipelineValue')}
                        value={kpis.openPipelineValue}
                        format={money}
                        icon="money"
                        tone="accent"
                        href={`${base}/deals/board`}
                    />
                    <DeferredData<DashboardPageProps, 'charts'>
                        keys={['charts']}
                        fallback={
                            <KpiTile
                                label={t('dashboard:kpis.ordersThisMonth')}
                                value={kpis.ordersThisMonth}
                                format={count}
                                icon="orders"
                                tone="info"
                                href={`${base}/orders`}
                            />
                        }
                    >
                        {({ charts }) => {
                            const { current, previous } = charts.ordersMonthToDate;

                            return (
                                <KpiTile
                                    label={t('dashboard:kpis.ordersThisMonth')}
                                    value={kpis.ordersThisMonth}
                                    format={count}
                                    icon="orders"
                                    tone="info"
                                    href={`${base}/orders`}
                                    trend={{ values: charts.ordersCount, label: t('dashboard:trends.ordersSeries') }}
                                    // Fără lună anterioară nu există variație de raportat — un
                                    // „+100%" față de zero e aritmetic, nu informativ.
                                    delta={
                                        previous === 0
                                            ? undefined
                                            : {
                                                  ratio: (current - previous) / previous,
                                                  format: percent,
                                                  goodWhen: 'up',
                                                  versus: t('dashboard:trends.versusLastMonth'),
                                              }
                                    }
                                />
                            );
                        }}
                    </DeferredData>
                    <KpiTile
                        label={t('dashboard:kpis.overdueInvoices')}
                        value={kpis.overdueInvoices.count}
                        format={count}
                        hint={money(kpis.overdueInvoices.amount)}
                        icon="clock"
                        tone={kpis.overdueInvoices.count > 0 ? 'danger' : 'success'}
                        href={`${base}/invoices`}
                    />
                    <KpiTile
                        label={t('dashboard:kpis.lowStockAlerts')}
                        value={kpis.lowStockAlerts}
                        format={count}
                        icon="alert"
                        tone={kpis.lowStockAlerts > 0 ? 'warning' : 'success'}
                        href={`${base}/products`}
                    />
                </div>

                <DeferredData<DashboardPageProps, 'charts'> keys={['charts']} fallback={<ChartSkeleton height={292} />}>
                    {({ charts }) => {
                        // Doar etapele deschise ajung aici (controllerul exclude Won/Lost), deci
                        // rampa `--stage-1…4` se aplică pe toate — de aceea `isWon`/`isLost` sunt
                        // `false` constant: `stageColors` e harta comună cu kanbanul, care PRIMEȘTE
                        // și etapele terminale.
                        const colors = stageColors(charts.pipeline.map((stage) => ({ id: stage.id, isWon: false, isLost: false })));
                        const funnel: BarListItem[] = charts.pipeline.map((stage) => ({
                            id: stage.id,
                            label: stage.name,
                            value: stage.value,
                            display: money(stage.value),
                            hint: t('dashboard:charts.pipelineHint', { count: stage.deals, probability: stage.probability }),
                            color: colors.get(stage.id) ?? 'var(--stage-1)',
                        }));
                        const slices = Object.entries(charts.ordersByStatus).map(([status, total]) => ({
                            id: status,
                            label: t(`orders:index.statuses.${statusKey(status)}`),
                            value: total,
                            color: ORDER_STATUS_COLOR[status] ?? 'var(--control)',
                        }));
                        const totalOrders = slices.reduce((sum, slice) => sum + slice.value, 0);

                        return (
                            <>
                                <div className="grid gap-4 lg:grid-cols-3">
                                    <Panel title={t('dashboard:charts.revenue')} className="lg:col-span-2">
                                        <AreaChart
                                            caption={t('dashboard:charts.revenueCaption')}
                                            categories={charts.months.map((month) => monthFormat.format(new Date(`${month}-01T00:00:00`)))}
                                            series={[
                                                { id: 'orders', label: t('dashboard:charts.seriesOrders'), values: charts.orders, tone: 1 },
                                                { id: 'won', label: t('dashboard:charts.seriesWon'), values: charts.wonDeals, tone: 2 },
                                            ]}
                                            formatValue={money}
                                            formatTick={compact}
                                        />
                                    </Panel>
                                    <Panel
                                        title={t('dashboard:charts.pipeline')}
                                        action={
                                            <Link href={`${base}/deals/board`} className="text-xs font-medium text-accent-text hover:underline">
                                                {t('dashboard:charts.openBoard')}
                                            </Link>
                                        }
                                    >
                                        {funnel.length === 0 ? (
                                            <p className="text-sm text-text-3">{t('dashboard:charts.empty')}</p>
                                        ) : (
                                            <BarList items={funnel} label={t('dashboard:charts.pipeline')} />
                                        )}
                                    </Panel>
                                </div>

                                <div className="grid gap-4 lg:grid-cols-3">
                                    <Panel title={t('dashboard:charts.ordersByStatus')}>
                                        {totalOrders === 0 ? (
                                            <p className="text-sm text-text-3">{t('dashboard:charts.empty')}</p>
                                        ) : (
                                            <Donut
                                                label={t('dashboard:charts.ordersByStatus')}
                                                slices={slices}
                                                center={formatNumber(totalOrders, locale)}
                                                centerLabel={t('dashboard:charts.ordersUnit')}
                                                formatValue={(value) => formatNumber(value, locale)}
                                            />
                                        )}
                                    </Panel>
                                    <Panel title={t('dashboard:attention.title')} className="lg:col-span-2">
                                        <DeferredData<DashboardPageProps, 'attention'> keys={['attention']} fallback={<ChartSkeleton height={150} />}>
                                            {({ attention }) => <AttentionList data={attention} currency={currency} />}
                                        </DeferredData>
                                    </Panel>
                                </div>
                            </>
                        );
                    }}
                </DeferredData>

                {activity !== null && (
                    <Panel title={t('dashboard:recentActivity')}>
                        <ActivityFeed items={activity} />
                    </Panel>
                )}
            </div>
        </>
    );
}

Dashboard.layout = (page: ReactNode) => <AppLayout>{page}</AppLayout>;
