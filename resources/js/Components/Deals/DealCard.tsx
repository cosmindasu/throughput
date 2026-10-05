import { Link } from '@inertiajs/react';
import type { DragEvent } from 'react';
import { useTranslation } from 'react-i18next';
import Avatar from '@/Components/Avatar';
import MoveStageMenu from '@/Components/Deals/MoveStageMenu';
import Icon from '@/Components/Icon';
import StatusBadge from '@/Components/StatusBadge';
import { useLocale } from '@/hooks/useLocale';
import { formatDate } from '@/lib/format';
import { formatMoney } from '@/lib/money';
import { relativeDays } from '@/lib/relativeTime';
import type { DealStage, DealSummary } from '@/types/generated';

interface DealCardProps {
    deal: DealSummary;
    stages: DealStage[];
    workspaceSlug: string;
    /** Miezul nopții LOCAL, calculat o singură dată de board — nu `Date.now()` per card. */
    today: number;
    dragging?: boolean;
    justMoved?: boolean;
    onDragStart: (event: DragEvent<HTMLDivElement>, deal: DealSummary) => void;
    onDragEnd?: () => void;
    onError: (message: string) => void;
    onMoved?: (stage: DealStage) => void;
}

/** Id DOM stabil al cardului, derivat din `deal.id` — ținta focusului explicit după o
 * mutare reușită (P2-002), indiferent dacă a fost declanșată din meniu sau din drag &
 * drop. */
export function dealCardDomId(dealId: string): string {
    return `deal-card-${dealId}`;
}

const MS_PER_DAY = 86_400_000;

/**
 * `expectedCloseDate` e „YYYY-MM-DD", o dată CALENDARISTICĂ fără fus. Se construiește cu
 * `new Date(an, lună, zi)` — constructorul LOCAL — nu prin `new Date('2026-10-12')`, pe care
 * specificația îl interpretează ca UTC: la est de Greenwich asta dă miezul nopții UTC, adică
 * tot ziua precedentă local, și termenul iese cu o zi mai devreme.
 */
function daysUntil(isoDate: string, today: number): number {
    const [year, month, day] = isoDate.split('-').map(Number);

    return Math.round((new Date(year, month - 1, day).getTime() - today) / MS_PER_DAY);
}

/**
 * Un card kanban (§9.3, §7.3). `can.moveStage` decide ATÂT `draggable`, CÂT ȘI prezența
 * meniului „Move to stage…" — un Viewer sau un Agent pe deal-ul altcuiva nu văd nici
 * drag handle, nici meniu (nu doar dezactivate, ABSENTE — FR-RBAC-01).
 *
 * Ierarhia cardului urmează întrebările în ordinea în care le pune cineva care se uită la un
 * board: CE afacere (titlul), la CINE (contul), CÂT (valoarea, cel mai mare element după
 * titlu), CÂND (termenul, dar numai când e aproape sau depășit — altfel e zgomot pe fiecare
 * card), și A CUI e (avatarul + numele, pe un rând separat de subsol).
 *
 * Termenul poartă ICON, nu doar tentă: „roșu" și „galben" nu se disting pentru cine nu
 * distinge culorile, iar diferența dintre „a trecut termenul" și „se apropie" e tocmai ce
 * trebuie citit dintr-o privire (SC 1.4.1).
 */
export default function DealCard({ deal, stages, workspaceSlug, today, dragging = false, justMoved = false, onDragStart, onDragEnd, onError, onMoved }: DealCardProps) {
    const { t } = useTranslation('deals');
    const locale = useLocale();

    // Doar pe afacerile DESCHISE: un termen pe una deja câștigată sau pierdută n-are ce
    // acțiune să ceară.
    const days = deal.status === 'open' && deal.expectedCloseDate ? daysUntil(deal.expectedCloseDate, today) : null;
    const dueTone = days === null ? null : days < 0 ? 'danger' : days <= 7 ? 'warning' : null;

    return (
        <div
            id={dealCardDomId(deal.id)}
            tabIndex={-1}
            draggable={deal.can.moveStage}
            onDragStart={(event) => deal.can.moveStage && onDragStart(event, deal)}
            onDragEnd={onDragEnd}
            data-dragging={dragging || undefined}
            data-just-moved={justMoved || undefined}
            aria-roledescription={deal.can.moveStage ? t('card.draggableRoleDescription') : undefined}
            className="rounded-md border border-border bg-surface p-3 shadow-sm transition-[transform,box-shadow,opacity] duration-150 hover:-translate-y-px hover:shadow-md focus:outline-none focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus motion-reduce:transition-none data-[dragging]:opacity-40 data-[just-moved]:motion-safe:animate-settle"
        >
            <Link href={`/${workspaceSlug}/deals/${deal.id}`} className="line-clamp-2 text-sm font-medium text-text hover:underline">
                {deal.title}
            </Link>
            <p className="mt-1 flex items-center gap-1.5 text-xs text-text-3">
                <Icon name="accounts" size={12} />
                <span className="truncate">{deal.account.name}</span>
            </p>

            <div className="mt-3 flex items-end justify-between gap-2">
                <p className="numeric text-base font-semibold text-text">{formatMoney(deal.value, deal.currency, locale, { maximumFractionDigits: 0 })}</p>
                {dueTone !== null && deal.expectedCloseDate !== null && days !== null && (
                    <StatusBadge tone={dueTone}>
                        <Icon name={dueTone === 'danger' ? 'warn' : 'calendar'} size={12} className="mr-1" />
                        {/*
                            „în 3 zile" se citește mai repede decât o dată, dar doar în jurul
                            prezentului: la trei săptămâni distanță, „în 21 de zile" cere o
                            socoteală pe care data o scutește. `title` păstrează mereu data
                            exactă.
                        */}
                        <time dateTime={deal.expectedCloseDate} title={formatDate(deal.expectedCloseDate, locale)}>
                            {days > -7 && days < 8 ? relativeDays(days, locale) : formatDate(deal.expectedCloseDate, locale)}
                        </time>
                    </StatusBadge>
                )}
            </div>

            {deal.status !== 'open' && (
                <div className="mt-2">
                    <StatusBadge tone={deal.status === 'won' ? 'success' : 'danger'}>
                        <Icon name={deal.status === 'won' ? 'check' : 'close'} size={12} className="mr-1" />
                        {deal.status === 'won'
                            ? t('status.won')
                            : t('card.lost', { reason: deal.lostReason ? t(`lostReasons.${deal.lostReason}`) : t('lostReasons.unknown') })}
                    </StatusBadge>
                </div>
            )}

            <div className="mt-3 flex items-center justify-between gap-2 border-t border-border-soft pt-2">
                <span className="flex min-w-0 items-center gap-2">
                    <Avatar id={deal.owner.id} name={deal.owner.name} />
                    <span className="truncate text-xs text-text-2">{deal.owner.name}</span>
                </span>
                {deal.can.moveStage && (
                    <MoveStageMenu
                        variant="icon"
                        workspaceSlug={workspaceSlug}
                        dealTitle={deal.title}
                        dealId={deal.id}
                        currentStageId={deal.stage.id}
                        stages={stages}
                        onError={onError}
                        onMoved={onMoved}
                    />
                )}
            </div>
        </div>
    );
}
