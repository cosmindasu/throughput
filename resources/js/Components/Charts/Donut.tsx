import { arc, pie } from 'd3-shape';

export interface DonutSlice {
    id: string;
    label: string;
    value: number;
    color: string;
}

interface DonutProps {
    slices: DonutSlice[];
    /** Textul din mijloc: totalul, deja formatat. */
    center: string;
    centerLabel: string;
    formatValue: (value: number) => string;
    label: string;
    size?: number;
}

/**
 * Donut cu LEGENDĂ-TABEL alături: fiecare felie apare ca rând cu valoare și procent, deci
 * graficul nu are nevoie de tooltip pentru a fi citit, iar legenda e alternativa textuală.
 * Feliile sunt separate printr-un contur de culoarea suprafeței (`--surface`), ca două
 * culori adiacente să nu depindă de contrastul lor reciproc.
 */
export default function Donut({ slices, center, centerLabel, formatValue, label, size = 148 }: DonutProps) {
    const total = slices.reduce((sum, slice) => sum + slice.value, 0);
    const radius = size / 2;
    const segment = arc<{ startAngle: number; endAngle: number }>().innerRadius(radius * 0.66).outerRadius(radius - 2).cornerRadius(3);
    const arcs = pie<DonutSlice>().sort(null).padAngle(0.02).value((slice) => slice.value)(slices);

    // Numele include TOTALUL din mijloc: el e desenat ca `<text>` într-un svg `aria-hidden` și
    // nu apare ca text nicăieri altundeva, deci fără el cifra centrală — cea mai mare de pe
    // grafic — lipsea complet pentru un cititor de ecran.
    return (
        <div role="group" aria-label={`${label}: ${center} ${centerLabel}`} className="flex flex-wrap items-center gap-6">
            <svg width={size} height={size} viewBox={`0 0 ${size} ${size}`} aria-hidden="true" focusable="false" className="shrink-0">
                <g transform={`translate(${radius},${radius})`}>
                    {arcs.map((slice) => (
                        <path key={slice.data.id} d={segment(slice) ?? ''} fill={slice.data.color} stroke="var(--surface)" strokeWidth={2} />
                    ))}
                    <text textAnchor="middle" y={2} className="numeric fill-text text-xl font-semibold">
                        {center}
                    </text>
                    <text textAnchor="middle" y={20} className="fill-text-3 text-[11px]">
                        {centerLabel}
                    </text>
                </g>
            </svg>
            <ul className="flex min-w-40 flex-1 flex-col gap-2 text-sm">
                {slices.map((slice) => (
                    <li key={slice.id} className="flex items-center justify-between gap-3">
                        <span className="flex items-center gap-2 text-text-2">
                            <span aria-hidden="true" className="size-2.5 shrink-0 rounded-sm" style={{ backgroundColor: slice.color }} />
                            {slice.label}
                        </span>
                        <span className="numeric text-text">
                            {/* `{' '}` explicit: `ml-1.5` e DOAR margine vizuală, deci fără el
                                arborele de accesibilitate (și orice copiere de text) lipește
                                cifrele — „4" urmat de „15%" se citea „415%". */}
                            {formatValue(slice.value)}{' '}
                            <span className="ml-1 text-xs text-text-3">{total > 0 ? Math.round((slice.value / total) * 100) : 0}%</span>
                        </span>
                    </li>
                ))}
            </ul>
        </div>
    );
}
