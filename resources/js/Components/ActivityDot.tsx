import { activityTone } from '@/lib/activityTone';

const toneDot: Record<string, string> = {
    neutral: 'bg-control',
    accent: 'bg-accent-fill',
    success: 'bg-success',
    warning: 'bg-warning',
    danger: 'bg-danger',
    info: 'bg-info',
};

/**
 * Semnalul vizual al unui rând de jurnal: ce fel de acțiune a fost.
 *
 * `aria-hidden`, deliberat și fără excepție — eticheta scrisă a acțiunii
 * (`description`/`actionLabel`) stă mereu imediat lângă el, deci punctul nu poartă nicio
 * informație în plus. Un nume accesibil aici ar face cititorul de ecran să anunțe de două
 * ori același lucru pe fiecare dintre cele 10-50 de rânduri ale unui jurnal.
 *
 * Culoarea e deci REDUNDANTĂ prin construcție, ceea ce e tot ce cere SC 1.4.1: cine nu
 * distinge verdele de roșu citește exact aceeași listă, doar fără scurtătura vizuală.
 */
export default function ActivityDot({ action }: { action: string }) {
    return (
        <span
            aria-hidden="true"
            className={`mt-1.5 size-2 shrink-0 rounded-full ${toneDot[activityTone(action)]}`}
        />
    );
}
