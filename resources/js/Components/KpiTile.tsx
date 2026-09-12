interface KpiTileProps {
    label: string;
    value: string;
    hint?: string;
}

/**
 * O singură placă KPI a dashboard-ului (FR-DEMO-01, §21.3). Valoarea vine
 * deja formatată din pagină (monedă/număr) — componenta nu presupune nimic
 * despre formatul sursă.
 */
export default function KpiTile({ label, value, hint }: KpiTileProps) {
    return (
        <div className="rounded-lg border border-border bg-surface p-4">
            <p className="text-sm text-text-2">{label}</p>
            <p className="numeric mt-1 text-2xl font-semibold text-text">{value}</p>
            {hint && <p className="numeric mt-1 text-xs text-text-3">{hint}</p>}
        </div>
    );
}
