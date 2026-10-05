import { useTranslation } from 'react-i18next';

/** Fallback pentru `<Deferred>`: aceeași înălțime ca graficul, ca pagina să nu „sară" la sosirea datelor. */
export default function ChartSkeleton({ height = 220 }: { height?: number }) {
    const { t } = useTranslation('common');

    return (
        <div role="status" aria-label={t('common:states.loadingLabel')} className="flex flex-col justify-end gap-2 rounded-md bg-raised p-4 motion-safe:animate-pulse" style={{ height }}>
            <div className="flex flex-1 items-end gap-2" aria-hidden="true">
                {[40, 65, 50, 80, 60, 90, 70, 55].map((h, index) => (
                    <div key={index} className="flex-1 rounded-sm bg-border" style={{ height: `${h}%` }} />
                ))}
            </div>
        </div>
    );
}
