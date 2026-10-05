import { useId } from 'react';
import { areaPath, linePath, linear } from './geometry';

interface SparklineProps {
    values: number[];
    /**
     * Textul alternativ. Lipsa lui face sparkline-ul DECOR (`aria-hidden`): într-un KPI,
     * cifra și delta din text sunt informația, linia doar o ilustrează.
     */
    label?: string;
    width?: number;
    height?: number;
    /** Culoarea vine din `currentColor`: dă o clasă `text-*` de tentă. Implicit `text-accent-text` (6,35:1 / 9,98:1), nu `accent-fill` (3,59:1 pe tema închisă). */
    className?: string;
}

export default function Sparkline({ values, label, width = 96, height = 28, className = 'text-accent-text' }: SparklineProps) {
    const gradientId = useId();

    if (values.length < 2) {
        return null;
    }

    const pad = 3;
    const x = linear(0, values.length - 1, pad, width - pad);
    const min = Math.min(...values);
    const max = Math.max(...values);
    const y = max === min ? () => height / 2 : linear(min, max, height - pad, pad);
    const points = values.map((value, index) => ({ x: x(index), y: y(value) }));
    const last = points[points.length - 1];

    return (
        <svg
            width={width}
            height={height}
            viewBox={`0 0 ${width} ${height}`}
            role={label ? 'img' : undefined}
            aria-label={label}
            aria-hidden={label ? undefined : true}
            focusable="false"
            className={`shrink-0 overflow-visible ${className}`}
        >
            <defs>
                <linearGradient id={gradientId} x1="0" x2="0" y1="0" y2="1">
                    <stop offset="0" stopColor="currentColor" stopOpacity="0.28" />
                    <stop offset="1" stopColor="currentColor" stopOpacity="0" />
                </linearGradient>
            </defs>
            <path d={areaPath(height)(points) ?? ''} fill={`url(#${gradientId})`} />
            <path d={linePath(points) ?? ''} fill="none" stroke="currentColor" strokeWidth={1.5} strokeLinecap="round" />
            <circle cx={last.x} cy={last.y} r={2.5} fill="currentColor" />
        </svg>
    );
}
