import { useTranslation } from 'react-i18next';
import ToneIcon from '@/Components/ToneIcon';
import { kindVisual } from '@/lib/activityKind';
import { useLocale } from '@/hooks/useLocale';
import { getDateTimeFormat } from '@/lib/format';
import type { ActivityItem } from '@/types/generated';

interface ActivityFeedProps {
    items: ActivityItem[];
}

/**
 * Opțiuni PĂSTRATE exact ca înainte de Val 3 (FR-I18N-03) — o A TREIA formă distinctă
 * (fără `year`, spre deosebire de `Pages/Activity/Index.tsx` și
 * `Components/History/HistoryTab.tsx`, care au aceleași 5 opțiuni cu `year`). NU se
 * unifică: sunt trei formate diferite ale aplicației, nu duplicate accidentale.
 */
const FEED_DATE_TIME_OPTIONS: Intl.DateTimeFormatOptions = {
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
};

/**
 * Ultimele 10 intrări din `activity_log` (FR-DEMO-01, §21.3). Faza 1
 * populează asta doar din seed-ul istoric — scrierea live vine în Faza 5
 * (plan-implementare.md §7.4) — componenta nu face nicio presupunere despre
 * sursă.
 */
export default function ActivityFeed({ items }: ActivityFeedProps) {
    const { t } = useTranslation('activity');
    const locale = useLocale();

    if (items.length === 0) {
        return <p className="text-sm text-text-2">{t('feed.empty')}</p>;
    }

    return (
        <ul className="data-rows divide-y divide-border-soft">
            {items.map((item) => (
                <li key={item.id} className="flex items-center justify-between gap-4 px-3 py-2 text-sm">
                    {/* `item.description`/`item.actor` vin GATA CONSTRUITE din
                        `App\Http\Resources\ActivityEntryResource` (interpolare + `Str::headline()`),
                        fără trecere prin catalog — backend, în afara celor 14 fișiere ale lotului. Nu
                        le reconstrui aici (vezi raportul). */}
                    {/* Iconul vine din `item.kind` (derivat pe server), NU din `item.action`:
                        enum-ul coloanei are 9 valori, dar o mutare de etapă, o factură plătită
                        și o editare de titlu sunt toate `updated`. Culoarea spune CONSECINȚA,
                        forma spune OBIECTUL — deci rândurile de rutină rămân neutre și doar ce
                        contează iese în evidență. `ToneIcon` e `aria-hidden` + `rounded-full`,
                        exact unul pe rând: contractul verificat de `list-rows.spec.ts`. */}
                    <div className="flex min-w-0 items-start gap-2.5">
                        <ToneIcon size="sm" name={kindVisual(item.kind).icon} tone={kindVisual(item.kind).tone} />
                        <div className="min-w-0">
                            <p className="truncate text-text">{item.description}</p>
                            {/* Numele înregistrării atinse, separat de fraza tradusă
                                (FR-I18N-06). Fără el, zece rânduri „Updated Deal" nu spun
                                CARE afacere. Autorul rămâne pe acelaşi rând, după un separator. */}
                            <p className="truncate text-xs text-text-3">
                                {item.subjectName ? (
                                    <>
                                        <span className="text-text-2">{item.subjectName}</span>
                                        <span aria-hidden="true"> · </span>
                                    </>
                                ) : null}
                                {item.actor}
                            </p>
                        </div>
                    </div>
                    <time dateTime={item.at} className="numeric shrink-0 text-xs text-text-3">
                        {getDateTimeFormat(locale, FEED_DATE_TIME_OPTIONS).format(new Date(item.at))}
                    </time>
                </li>
            ))}
        </ul>
    );
}
