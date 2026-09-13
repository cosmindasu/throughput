import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState, type DragEvent, type ReactNode } from 'react';
import { ButtonLink } from '@/Components/Button';
import DealCard from '@/Components/Deals/DealCard';
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
    const [draggedDeal, setDraggedDeal] = useState<DealSummary | null>(null);
    const [pendingLostMove, setPendingLostMove] = useState<PendingLostMove | null>(null);
    const [dialogProcessing, setDialogProcessing] = useState(false);

    const allStages = columns.map((column) => column.stage);

    const move = (deal: DealSummary, targetStage: DealStage, lostReason?: LostReason) => {
        const previousColumns = columns;
        setColumns((current) => applyOptimisticMove(current, deal, targetStage));
        setErrorMessage(null);
        setDialogProcessing(true);

        router.patch(
            `/${workspaceSlug}/deals/${deal.id}/stage`,
            { to_stage_id: targetStage.id, ...(lostReason ? { lost_reason: lostReason } : {}) },
            {
                preserveScroll: true,
                onError: (errors) => {
                    setColumns(previousColumns);
                    setErrorMessage(errors.to_stage_id ?? errors.lost_reason ?? 'Could not move this deal.');
                },
                onSuccess: () => setPendingLostMove(null),
                onFinish: () => setDialogProcessing(false),
            },
        );
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

Kanban.layout = (page: ReactNode) => <AppLayout>{page}</AppLayout>;
