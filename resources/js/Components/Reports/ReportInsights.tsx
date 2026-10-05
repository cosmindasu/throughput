import { useTranslation } from 'react-i18next';
import BarList, { type BarListItem } from '@/Components/Charts/BarList';
import Panel from '@/Components/Panel';
import { useLocale } from '@/hooks/useLocale';
import { formatNumber } from '@/lib/format';
import type { ReportsIndexPageProps } from '@/types/generated';

type Velocity = NonNullable<ReportsIndexPageProps['insights']>['velocity'];

/**
 * Raportul „Deal velocity" — care EXISTĂ deja (`App\Support\Reports\DealVelocityReport`) —
 * randat ca ceea ce este: două liste de bare, nu un tabel de cinci coloane. Aceleași rânduri,
 * aceeași interogare; se schimbă doar forma.
 *
 * Două grafice, nu unul, fiindcă răspund la întrebări diferite și au unități diferite: câte
 * afaceri ajung la fiecare etapă (unde se îngustează pâlnia) și cât stau acolo (unde se
 * împotmolesc). Suprapuse pe aceeași axă, numărul ar înghiți zilele.
 *
 * Etapele fără durată medie lipsesc din al doilea grafic, nu apar cu zero: nicio afacere n-a
 * PĂRĂSIT încă etapa, ceea ce e altceva decât „se iese imediat".
 */
export default function ReportInsights({ velocity }: { velocity: Velocity }) {
    const { t } = useTranslation('reports');
    const locale = useLocale();

    const reached: BarListItem[] = velocity.map(([, stage, , dealsReached, conversion], index) => ({
        id: `reached-${stage}`,
        label: stage,
        value: dealsReached,
        display: formatNumber(dealsReached, locale),
        // `formatNumber`, nu numărul brut: i18next NU localizează un parametru numeric
        // interpolat, deci „77.9" rămânea cu punct și în franceză. Același tipar ca
        // `bulk.json` — `count` pentru pluralizare, `formatted` pentru afișare.
        hint: conversion === null ? undefined : t('reports:insights.advance', { formatted: formatNumber(conversion, locale) }),
        color: `var(--stage-${Math.min(index + 1, 4)})`,
    }));

    const days: BarListItem[] = velocity
        .filter((row) => row[2] !== null)
        .map(([, stage, avgDays], index) => ({
            id: `days-${stage}`,
            label: stage,
            value: avgDays ?? 0,
            display: t('reports:insights.days', { count: avgDays ?? 0, formatted: formatNumber(avgDays ?? 0, locale) }),
            color: `var(--series-${(index % 6) + 1})`,
        }));

    return (
        <div className="grid gap-4 lg:grid-cols-2">
            <Panel title={t('reports:insights.funnel')}>
                {reached.length === 0 ? (
                    <p className="text-sm text-text-3">{t('reports:insights.empty')}</p>
                ) : (
                    <BarList items={reached} label={t('reports:insights.funnel')} />
                )}
            </Panel>
            <Panel title={t('reports:insights.timeInStage')}>
                {days.length === 0 ? (
                    <p className="text-sm text-text-3">{t('reports:insights.empty')}</p>
                ) : (
                    <BarList items={days} label={t('reports:insights.timeInStage')} />
                )}
            </Panel>
        </div>
    );
}
