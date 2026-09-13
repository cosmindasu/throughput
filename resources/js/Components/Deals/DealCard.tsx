import { Link } from '@inertiajs/react';
import type { DragEvent } from 'react';
import MoveStageMenu from '@/Components/Deals/MoveStageMenu';
import StatusBadge from '@/Components/StatusBadge';
import { formatMoney } from '@/lib/money';
import type { DealStage, DealSummary } from '@/types/generated';

interface DealCardProps {
    deal: DealSummary;
    stages: DealStage[];
    workspaceSlug: string;
    onDragStart: (event: DragEvent<HTMLDivElement>, deal: DealSummary) => void;
    onError: (message: string) => void;
}

/**
 * Un card kanban (§9.3, §7.3). `can.moveStage` decide ATÂT `draggable`, CÂT ȘI prezența
 * meniului „Move to stage…" — un Viewer sau un Agent pe deal-ul altcuiva nu văd nici
 * drag handle, nici meniu (nu doar dezactivate, ABSENTE — FR-RBAC-01).
 */
export default function DealCard({ deal, stages, workspaceSlug, onDragStart, onError }: DealCardProps) {
    return (
        <div
            draggable={deal.can.moveStage}
            onDragStart={(event) => deal.can.moveStage && onDragStart(event, deal)}
            className="rounded-md border border-border bg-surface p-3 shadow-sm"
            aria-roledescription={deal.can.moveStage ? 'Draggable deal card' : undefined}
        >
            <Link href={`/${workspaceSlug}/deals/${deal.id}`} className="text-sm font-medium text-text hover:underline">
                {deal.title}
            </Link>

            <p className="numeric mt-1 text-sm text-text-2">{formatMoney(deal.value, deal.currency)}</p>
            <p className="mt-1 truncate text-xs text-text-3">{deal.account.name}</p>
            <p className="mt-1 truncate text-xs text-text-3">{deal.owner.name}</p>

            {deal.status !== 'open' && (
                <div className="mt-2">
                    <StatusBadge tone={deal.status === 'won' ? 'success' : 'danger'}>
                        {deal.status === 'won' ? 'Won' : `Lost — ${deal.lostReason ?? 'unknown'}`}
                    </StatusBadge>
                </div>
            )}

            {deal.can.moveStage && (
                <div className="mt-2">
                    <MoveStageMenu
                        workspaceSlug={workspaceSlug}
                        dealId={deal.id}
                        currentStageId={deal.stage.id}
                        stages={stages}
                        onError={onError}
                    />
                </div>
            )}
        </div>
    );
}
