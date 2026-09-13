import { Head, Link, router, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState, type DragEvent, type ReactNode } from 'react';
import { ButtonLink } from '@/Components/Button';
import DealCard, { dealCardDomId } from '@/Components/Deals/DealCard';
import LostReasonDialog from '@/Components/Deals/LostReasonDialog';
import ViewSwitcher from '@/Components/Deals/ViewSwitcher';
import AppLayout from '@/Layouts/AppLayout';
import type { DealsBoardColumn, DealsKanbanPageProps, DealStage, DealSummary, LostReason } from '@/types/generated';

interface PendingLostMove {
    deal: DealSummary;
    targetStage: DealStage;
}

/**
 * `Deals/Kanban` — §9.3. Coloane = etapele pipeline-ului implicit, după `position`.
 * Drag & drop HTML5 nativ, optimist, cu revenire vizuală + mesaj explicit la respingere
 * server (ex: „Set a deal value before marking as Won"). Alternativa de tastatură
 * (`MoveStageMenu`, FR-DEAL-01) e pe fiecare card, nu doar pe unele.
 */
export default function Kanban() {
    const { props } = usePage<DealsKanbanPageProps>();
    const { pipeline, columns: serverColumns, ownerFilter, can, workspace } = props;
    const workspaceSlug = workspace?.slug ?? '';

    const [columns, setColumns] = useState<DealsBoardColumn[]>(serverColumns);
    // Sincronizarea cu props-ul de server NU se face într-un `useEffect` (ar declanșa un
    // al doilea randare, cascadat, doar ca să copieze un prop în state — React 19
    // semnalează exact acest anti-pattern). Tiparul recomandat: comparăm referința în
    // timpul randării și „ajustăm" state-ul direct — React reia randarea, fără commit
    // intermediar. Necesar fiindcă state-ul local ține și mutările optimiste ale
    // drag & drop, deci nu poate fi doar `serverColumns` direct.
    const [syncedServerColumns, setSyncedServerColumns] = useState(serverColumns);
    if (serverColumns !== syncedServerColumns) {
        setSyncedServerColumns(serverColumns);
        setColumns(serverColumns);
    }

    const [errorMessage, setErrorMessage] = useState<string | null>(null);
    const [announcement, setAnnouncement] = useState('');
    const [draggedDeal, setDraggedDeal] = useState<DealSummary | null>(null);
    const [pendingLostMove, setPendingLostMove] = useState<PendingLostMove | null>(null);
    const [dialogProcessing, setDialogProcessing] = useState(false);
    // Id-ul cardului al cărui focus trebuie restaurat explicit, ODATĂ ce coloanele
    // reflectă deja mutarea (P2-002) — cardul se remontează într-o altă coloană (părinte
    // VDOM diferit), deci React nu-l reconciliază după `key`, iar elementul care avea
    // focus rămâne detașat din DOM. Într-un `ref`, nu `useState`: efectul de mai jos DOAR
    // citește valoarea și mută focusul (o sincronizare cu DOM-ul, nu o schimbare de
    // stare React), deci n-are ce `setState` să declanșeze randări în cascadă.
    const pendingFocusDealId = useRef<string | null>(null);
    const [focusTick, setFocusTick] = useState(0);

    const allStages = columns.map((column) => column.stage);

    useEffect(() => {
        if (!pendingFocusDealId.current) {
            return;
        }

        document.getElementById(dealCardDomId(pendingFocusDealId.current))?.focus();
        pendingFocusDealId.current = null;
    }, [focusTick, columns]);

    const requestFocus = (dealId: string) => {
        pendingFocusDealId.current = dealId;
        setFocusTick((tick) => tick + 1);
    };

    const move = (deal: DealSummary, targetStage: DealStage, lostReason?: LostReason) => {
        // Focusul se restaurează DOAR dacă era deja pe cardul ăsta (drag & drop e
        // mouse-driven, dar un utilizator poate avea focusul pe declanșatorul „Move to
        // stage…" de la o interacțiune anterioară) — altfel am fura focusul de pe un alt
        // element al paginii pe care utilizatorul îl folosea în timp ce cererea era în zbor.
        const cardElement = document.getElementById(dealCardDomId(deal.id));
        const restoreFocus = cardElement !== null && cardElement.contains(document.activeElement);

        setColumns((current) => applyOptimisticMove(current, deal, targetStage));
        setErrorMessage(null);
        setDialogProcessing(true);

        router.patch(
            `/${workspaceSlug}/deals/${deal.id}/stage`,
            { to_stage_id: targetStage.id, ...(lostReason ? { lost_reason: lostReason } : {}) },
            {
                preserveScroll: true,
                onError: (errors) => {
                    // Revert PUNCTUAL, doar pentru ACEST deal, aplicat pe starea CURENTĂ
                    // printr-un updater funcțional — NU pe un instantaneu de dinaintea
                    // mutării (P2-001): dacă între timp un ALT card a fost mutat (reușit
                    // sau încă în zbor), un `setColumns(previousColumns)` l-ar șterge
                    // vizual, deși respingerea asta nu-l privește.
                    setColumns((current) => revertOptimisticMove(current, deal, targetStage));
                    setErrorMessage(errors.to_stage_id ?? errors.lost_reason ?? 'Could not move this deal.');
                },
                onSuccess: () => {
                    setPendingLostMove(null);
                    setAnnouncement(`Moved ${deal.title} to ${targetStage.name}`);
                    if (restoreFocus) {
                        requestFocus(deal.id);
                    }
                },
                onFinish: () => setDialogProcessing(false),
            },
        );
    };

    const handleCardMoved = (deal: DealSummary, targetStage: DealStage) => {
        setAnnouncement(`Moved ${deal.title} to ${targetStage.name}`);
        requestFocus(deal.id);
    };

    const handleDrop = (event: DragEvent<HTMLElement>, targetStage: DealStage) => {
        event.preventDefault();

        if (!draggedDeal || draggedDeal.stage.id === targetStage.id) {
            setDraggedDeal(null);
            return;
        }

        if (targetStage.isLost) {
            setPendingLostMove({ deal: draggedDeal, targetStage });
            setDraggedDeal(null);
            return;
        }

        move(draggedDeal, targetStage);
        setDraggedDeal(null);
    };

    const ownerFilterUrl = (value: 'me' | 'all') => `/${workspaceSlug}/deals/board?owner=${value}`;
    const viewAllUrl = (stageId: string) =>
        `/${workspaceSlug}/deals?filter[stage]=${stageId}${ownerFilter === 'me' ? '&filter[owner]=me' : ''}`;

    return (
        <>
            <Head title="Pipeline board" />

            <div className="flex flex-col gap-6">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h1 className="text-xl font-semibold text-text">{pipeline.name}</h1>
                        <p className="mt-1 text-sm text-text-2">Drag a card to another stage, or use “Move to stage…”.</p>
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        <ViewSwitcher workspaceSlug={workspaceSlug} active="board" />

                        <div className="flex overflow-hidden rounded-md border border-control text-sm">
                            <OwnerToggleLink href={ownerFilterUrl('me')} active={ownerFilter === 'me'}>
                                My deals
                            </OwnerToggleLink>
                            <OwnerToggleLink href={ownerFilterUrl('all')} active={ownerFilter === 'all'}>
                                All deals
                            </OwnerToggleLink>
                        </div>

                        {can.managePipeline && (
                            <ButtonLink href={`/${workspaceSlug}/pipeline`}>Manage pipeline</ButtonLink>
                        )}
                    </div>
                </div>

                {errorMessage && (
                    <p role="alert" className="rounded-md bg-danger-tint px-3 py-2 text-sm text-danger">
                        {errorMessage}
                    </p>
                )}

                {/* Regiune SEPARATĂ de alerta de eroare de mai sus (P2-003): o alertă
                    întrerupe imediat cititorul de ecran, o anunțare „polite" așteaptă o
                    pauză — succesul unei mutări nu justifică întreruperea. */}
                <p aria-live="polite" role="status" className="sr-only">
                    {announcement}
                </p>

                <div className="flex gap-4 overflow-x-auto pb-4">
                    {columns.map((column) => (
                        <section
                            key={column.stage.id}
                            aria-label={column.stage.name}
                            onDragOver={(event) => event.preventDefault()}
                            onDrop={(event) => handleDrop(event, column.stage)}
                            className="flex w-72 shrink-0 flex-col gap-3 rounded-lg border border-border bg-raised p-3"
                        >
                            <header className="flex items-center justify-between gap-2">
                                <h2 className="text-sm font-semibold text-text">{column.stage.name}</h2>
                                <span className="numeric text-xs text-text-3">{column.total}</span>
                            </header>

                            <div className="flex flex-col gap-2">
                                {column.deals.map((deal) => (
                                    <DealCard
                                        key={deal.id}
                                        deal={deal}
                                        stages={allStages}
                                        workspaceSlug={workspaceSlug}
                                        onDragStart={(_event, draggedDealCard) => setDraggedDeal(draggedDealCard)}
                                        onError={setErrorMessage}
                                        onMoved={(stage) => handleCardMoved(deal, stage)}
                                    />
                                ))}

                                {column.deals.length === 0 && (
                                    <p className="rounded-md border border-dashed border-border px-3 py-6 text-center text-xs text-text-3">
                                        No deals on this stage.
                                    </p>
                                )}
                            </div>

                            {column.hasMore && (
                                <Link
                                    href={viewAllUrl(column.stage.id)}
                                    className="text-center text-xs font-medium text-accent-text hover:underline"
                                >
                                    View all {column.total}
                                </Link>
                            )}
                        </section>
                    ))}
                </div>
            </div>

            <LostReasonDialog
                open={pendingLostMove !== null}
                processing={dialogProcessing}
                onCancel={() => setPendingLostMove(null)}
                onConfirm={(reason) => pendingLostMove && move(pendingLostMove.deal, pendingLostMove.targetStage, reason)}
            />
        </>
    );
}

function OwnerToggleLink({ href, active, children }: { href: string; active: boolean; children: ReactNode }) {
    return (
        <Link
            href={href}
            preserveScroll
            aria-current={active ? 'page' : undefined}
            className={`px-3 py-1.5 transition-colors ${active ? 'bg-accent-fill text-accent-on' : 'bg-surface text-text-2 hover:bg-row-hover'}`}
        >
            {children}
        </Link>
    );
}

function applyOptimisticMove(columns: DealsBoardColumn[], deal: DealSummary, target: DealStage): DealsBoardColumn[] {
    const movedDeal: DealSummary = {
        ...deal,
        stage: { id: target.id, name: target.name, isWon: target.isWon, isLost: target.isLost },
        status: target.isWon ? 'won' : target.isLost ? 'lost' : 'open',
    };

    return columns.map((column) => {
        if (column.stage.id === deal.stage.id) {
            return { ...column, deals: column.deals.filter((item) => item.id !== deal.id), total: Math.max(column.total - 1, 0) };
        }

        if (column.stage.id === target.id) {
            return { ...column, deals: [movedDeal, ...column.deals], total: column.total + 1 };
        }

        return column;
    });
}

/**
 * Inversul PUNCTUAL al `applyOptimisticMove`, pentru un deal respins de server (P2-001).
 * Operează pe `columns` dat — apelantul îl aplică printr-un updater funcțional pe starea
 * CURENTĂ, nu pe un instantaneu vechi, ca să compună corect cu mutări concurente ale
 * altor carduri.
 *
 * Verifică ÎNTÂI dacă deal-ul mai e, de fapt, în `targetStage` — o resincronizare din
 * props-urile serverului (declanșată de succesul ALTEI mutări, care reîncarcă tot
 * board-ul) poate fi ajuns între timp și poate fi mutat deja deal-ul înapoi pe baza
 * stării reale din DB. Fără verificarea asta, revenirea ar decrementa `total` a doua
 * oară pentru un card care nu mai e acolo.
 */
function revertOptimisticMove(columns: DealsBoardColumn[], deal: DealSummary, targetStage: DealStage): DealsBoardColumn[] {
    const targetColumn = columns.find((column) => column.stage.id === targetStage.id);
    const stillOptimisticallyThere = targetColumn?.deals.some((item) => item.id === deal.id) ?? false;

    if (!stillOptimisticallyThere) {
        return columns;
    }

    return columns.map((column) => {
        if (column.stage.id === targetStage.id) {
            return { ...column, deals: column.deals.filter((item) => item.id !== deal.id), total: Math.max(column.total - 1, 0) };
        }

        if (column.stage.id === deal.stage.id) {
            return { ...column, deals: [deal, ...column.deals], total: column.total + 1 };
        }

        return column;
    });
}

Kanban.layout = (page: ReactNode) => <AppLayout>{page}</AppLayout>;
