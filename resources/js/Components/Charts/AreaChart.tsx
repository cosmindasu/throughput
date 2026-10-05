import { useId, useState, type PointerEvent } from 'react';
import { useTranslation } from 'react-i18next';
import { areaPath, linePath, linear, niceMax } from './geometry';
import { useContainerWidth } from './useContainerWidth';

export interface AreaSeries {
    id: string;
    label: string;
    values: number[];
    /** 1-6 → `--series-N`. Seria 2+ se desenează și întrerupt: culoarea nu e singurul indiciu (SC 1.4.1). */
    tone: 1 | 2 | 3 | 4 | 5 | 6;
}

interface AreaChartProps {
    /** Etichetele axei X, deja formatate pe limba curentă (ex. „Mar 2026"). */
    categories: string[];
    series: AreaSeries[];
    formatValue: (value: number) => string;
    formatTick: (value: number) => string;
    /** Titlul vizibil ȘI numele accesibil al graficului. */
    caption: string;
    height?: number;
}

const MARGIN = { top: 12, right: 12, bottom: 26, left: 44 };

export default function AreaChart({ categories, series, formatValue, formatTick, caption, height = 220 }: AreaChartProps) {
    const { t } = useTranslation('common');
    const captionId = useId();
    const gradientId = useId();
    const { ref, width } = useContainerWidth<HTMLDivElement>();
    const [active, setActive] = useState<number | null>(null);

    const count = categories.length;
    const innerWidth = width - MARGIN.left - MARGIN.right;
    const innerHeight = height - MARGIN.top - MARGIN.bottom;
    // Pragul de 4, nu de 1: pe un workspace nou, cu toate valorile zero, `niceMax(1)` dădea
    // o axă de 0 / 0,3 / 0,5 / 0,8 / 1 — fracțiuni de unitate pe un grafic de venit, formatate
    // compact și fără monedă. Patru trepte întregi se citesc ca o axă goală, ceea ce și este.
    const top = niceMax(Math.max(...series.flatMap((s) => s.values), 4));
    const x = linear(0, Math.max(count - 1, 1), 0, innerWidth);
    const y = linear(0, top, innerHeight, 0);
    const ticks = [0, 0.25, 0.5, 0.75, 1].map((fraction) => top * fraction);
    const labelEvery = Math.ceil(count / Math.max(Math.floor(innerWidth / 56), 1));

    const indexFromPointer = (event: PointerEvent<SVGSVGElement>): number => {
        const box = event.currentTarget.getBoundingClientRect();
        const ratio = (event.clientX - box.left - MARGIN.left) / innerWidth;

        return Math.min(Math.max(Math.round(ratio * (count - 1)), 0), count - 1);
    };

    return (
        <figure className="flex flex-col gap-3">
            <figcaption className="flex flex-wrap items-center justify-between gap-2 text-sm font-medium text-text-2">
                {/* `id` pe TEXTUL legendei, nu pe tot `figcaption`-ul: altfel numele accesibil
                    al graficului devenea „…ultimele 12 luni Orders Won deals", cu etichetele
                    legendei lipite la coadă. */}
                <span id={captionId}>{caption}</span>
                <ul className="flex gap-3 text-xs font-normal text-text-2">
                    {series.map((s, index) => (
                        <li key={s.id} className="flex items-center gap-1.5">
                            <svg width="16" height="8" aria-hidden="true">
                                <line x1="0" x2="16" y1="4" y2="4" stroke={`var(--series-${s.tone})`} strokeWidth="2" strokeDasharray={index === 0 ? undefined : '4 3'} />
                            </svg>
                            {s.label}
                        </li>
                    ))}
                </ul>
            </figcaption>

            {/*
                `width === 0` = containerul n-a fost încă măsurat (vezi `useContainerWidth`).
                Se rezervă ÎNĂLȚIMEA, dar nu se desenează nimic: un svg randat la o lățime
                ghicită depășește panoul pentru un cadru și împinge documentul lateral.
            */}
            <div ref={ref} className="relative" style={width === 0 ? { height } : undefined}>
                {width > 0 && (
                <>
                {/*
                    `role="img"` cu un nume, NU `role="group"` focalizabil cu săgeți.

                    Varianta cu `tabIndex={0}` + ArrowLeft/Right arăta ca o alternativă de
                    tastatură, dar nu era una: săgețile mutau DOAR un tooltip `aria-hidden`,
                    fără nicio regiune live, deci un cititor de ecran nu primea nimic — iar în
                    modul de navigare al cititoarelor săgețile nici nu ajung la handler. Pe
                    deasupra, prima apăsare sărea peste prima lună (`(current ?? 0) + 1`), iar
                    Escape funcționa doar cât timp svg-ul avea focus.
                    
                    Alternativa REALĂ există deja și e mai bună: tabelul „View as table" de mai
                    jos, cu toate cifrele, parcurgibil normal. Tooltip-ul rămâne ce a fost
                    mereu — o înlesnire pentru mouse.
                */}
                <svg
                    width={width}
                    height={height}
                    role="img"
                    aria-labelledby={captionId}
                    aria-roledescription={t('common:charts.roleDescription')}
                    onPointerMove={(event) => setActive(indexFromPointer(event))}
                    onPointerLeave={() => setActive(null)}
                    className="overflow-visible rounded-md"
                >
                    <defs>
                        <linearGradient id={gradientId} x1="0" x2="0" y1="0" y2="1">
                            <stop offset="0" stopColor="var(--series-1)" stopOpacity="0.30" />
                            <stop offset="1" stopColor="var(--series-1)" stopOpacity="0" />
                        </linearGradient>
                    </defs>
                    <g transform={`translate(${MARGIN.left},${MARGIN.top})`}>
                        {/* Etichetele axelor sunt `<text>`, deci cititorul de ecran le înșira ca
                            CONȚINUT al graficului („0 125K 250K … Nov Dec Jan"). Tabelul de mai
                            jos le dă în formă citibilă; aici sunt decor. */}
                        <g aria-hidden="true">
                        {ticks.map((tick) => (
                            <g key={tick} transform={`translate(0,${y(tick)})`}>
                                <line x2={innerWidth} stroke="var(--chart-grid)" />
                                <text x={-8} dy="0.32em" textAnchor="end" fill="var(--chart-axis)" fontSize={11} className="numeric">
                                    {formatTick(tick)}
                                </text>
                            </g>
                        ))}
                        {categories.map((category, index) =>
                            index % labelEvery === 0 ? (
                                <text key={category} x={x(index)} y={innerHeight + 18} textAnchor="middle" fill="var(--chart-axis)" fontSize={11}>
                                    {category}
                                </text>
                            ) : null,
                        )}
                        </g>
                        {series.map((s, index) => {
                            const points = s.values.map((value, i) => ({ x: x(i), y: y(value) }));

                            return (
                                <g key={s.id} className="motion-safe:animate-rise">
                                    {index === 0 && <path d={areaPath(innerHeight)(points) ?? ''} fill={`url(#${gradientId})`} />}
                                    <path
                                        d={linePath(points) ?? ''}
                                        fill="none"
                                        stroke={`var(--series-${s.tone})`}
                                        strokeWidth={2}
                                        strokeLinecap="round"
                                        strokeDasharray={index === 0 ? undefined : '6 4'}
                                    />
                                </g>
                            );
                        })}
                        {active !== null && (
                            <g aria-hidden="true">
                                <line x1={x(active)} x2={x(active)} y2={innerHeight} stroke="var(--control)" strokeDasharray="3 3" />
                                {series.map((s) => (
                                    <circle key={s.id} cx={x(active)} cy={y(s.values[active] ?? 0)} r={4} fill="var(--surface)" stroke={`var(--series-${s.tone})`} strokeWidth={2} />
                                ))}
                            </g>
                        )}
                    </g>
                </svg>

                {active !== null && (
                    <div
                        aria-hidden="true"
                        className="pointer-events-none absolute top-0 z-10 min-w-36 rounded-md border border-border bg-overlay px-3 py-2 text-xs shadow-lg"
                        style={{ left: Math.min(Math.max(MARGIN.left + x(active) - 72, 0), width - 150), top: 0 }}
                    >
                        <p className="font-medium text-text">{categories[active]}</p>
                        {series.map((s) => (
                            <p key={s.id} className="numeric mt-0.5 flex justify-between gap-4 text-text-2">
                                <span>{s.label}</span>
                                <span className="text-text">{formatValue(s.values[active] ?? 0)}</span>
                            </p>
                        ))}
                    </div>
                )}
                </>
                )}
            </div>

            {/* Alternativa textuală e VIZIBILĂ la cerere, nu ascunsă: același tabel servește cititorul de
                ecran, tastatura și pe cine vrea cifrele exacte. */}
            <details className="text-xs text-text-2">
                <summary className="w-fit cursor-pointer rounded-sm text-accent-text hover:underline">{t('common:charts.viewAsTable')}</summary>
                {/*
                    `tabIndex`/`role`/`aria-label`: un container derulabil fără ele e o regiune
                    pe care tastatura n-o poate parcurge (axe `scrollable-region-focusable`,
                    impact „serious") și pe care cititorul de ecran o anunță fără nume. Tiparul
                    e cel deja folosit de tabelele proiectului (`data-table-scroll`).
                */}
                <div tabIndex={0} role="region" aria-label={caption} className="mt-2 max-h-56 overflow-auto rounded-md border border-border focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus">
                    <table className="w-full text-left">
                        <caption className="sr-only">{caption}</caption>
                        <thead className="bg-raised">
                            <tr>
                                <th scope="col" className="px-3 py-1.5 font-medium">
                                    {/* Antet de coloană gol = axe `empty-table-header`. Coloana
                                        ține eticheta axei X, deci numele ei e chiar asta. */}
                                    <span className="sr-only">{t('common:charts.categoryColumn')}</span>
                                </th>
                                {series.map((s) => (
                                    <th key={s.id} scope="col" className="px-3 py-1.5 text-right font-medium">
                                        {s.label}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-border-soft">
                            {categories.map((category, index) => (
                                <tr key={category}>
                                    <th scope="row" className="px-3 py-1.5 font-normal">
                                        {category}
                                    </th>
                                    {series.map((s) => (
                                        <td key={s.id} className="numeric px-3 py-1.5 text-right">
                                            {formatValue(s.values[index] ?? 0)}
                                        </td>
                                    ))}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </details>
        </figure>
    );
}
