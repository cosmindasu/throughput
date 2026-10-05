import { area, curveMonotoneX, line } from 'd3-shape';

export interface Point {
    x: number;
    y: number;
}

/**
 * Scară liniară minimală. `d3-scale` ar aduce încă ~15 KB gzip (array, format, interpolate,
 * time) pentru trei rânduri de matematică; din d3 rămâne doar `d3-shape` (≈4 KB gzip).
 */
export const linear = (d0: number, d1: number, r0: number, r1: number) => {
    const span = d1 - d0 || 1;

    return (value: number): number => r0 + ((value - d0) / span) * (r1 - r0);
};

export const linePath = line<Point>()
    .x((p) => p.x)
    .y((p) => p.y)
    .curve(curveMonotoneX);

export const areaPath = (baseline: number) =>
    area<Point>()
        .x((p) => p.x)
        .y0(baseline)
        .y1((p) => p.y)
        .curve(curveMonotoneX);

/** Plafon „rotund" (1/2/5 × 10^n), ca ultima linie de grilă să cadă pe o cifră citibilă. */
export function niceMax(value: number): number {
    if (value <= 0) {
        return 1;
    }

    const magnitude = 10 ** Math.floor(Math.log10(value));
    const normalized = value / magnitude;
    const step = normalized <= 1 ? 1 : normalized <= 2 ? 2 : normalized <= 5 ? 5 : 10;

    return step * magnitude;
}
