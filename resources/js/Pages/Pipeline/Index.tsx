import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useState, type DragEvent, type FormEvent, type ReactNode } from 'react';
import Button from '@/Components/Button';
import ConfirmDialog from '@/Components/ConfirmDialog';
import EmptyState from '@/Components/EmptyState';
import Field, { controlClass } from '@/Components/Form/Field';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import AppLayout from '@/Layouts/AppLayout';
import type { PipelinePageProps, PipelineStage } from '@/types/generated';

/**
 * Configurare pipeline/etape (FR-DEAL-02, BR-DEAL-01, specs.md §9.2/§9.5). MVP: un singur
 * pipeline implicit per tenant — nicio rută de aici nu poartă `{pipeline}` în cale (vezi
 * `Pipeline::resolveDefault()`). Kanban-ul (alt pachet) CITEȘTE aceleași etape prin
 * `deals.view`; acest ecran e singurul care le administrează (`pipelines.manage`).
 *
 * Nu există Ziggy în proiect (vezi `AppLayout.tsx`) — căile se construiesc literal, cu
 * segmentul de workspace din propul comun `workspace`.
 */
export default function PipelineIndex() {
    const { workspace, pipelineName, stages, can } = usePage<PipelinePageProps>().props;
    const workspaceSlug = workspace?.slug ?? '';
    const stagesPath = `/${workspaceSlug}/pipeline/stages`;

    const [order, setOrder] = useState<string[]>(() => stages.map((stage) => stage.id));
    const [draggedId, setDraggedId] = useState<string | null>(null);
    const [editingId, setEditingId] = useState<string | null>(null);
    const [confirmingDeleteId, setConfirmingDeleteId] = useState<string | null>(null);
    const [deleting, setDeleting] = useState(false);

    // `stages` (props) e sursa de adevăr; `order` e doar starea optimistă de afișare între
    // click/drop și răspunsul serverului. La orice reîncărcare Inertia (după un
    // create/edit/delete/reorder reușit), cele două se resincronizează.
    const currentOrder = stages.every((stage) => order.includes(stage.id)) && order.length === stages.length
        ? order
        : stages.map((stage) => stage.id);

    const stagesById = new Map(stages.map((stage) => [stage.id, stage]));
    const orderedStages = currentOrder.map((id) => stagesById.get(id)).filter((s): s is PipelineStage => s !== undefined);

    function commitOrder(nextOrder: string[]) {
        const previous = currentOrder;
        setOrder(nextOrder);

        router.put(
            `${stagesPath}/order`,
            { stage_ids: nextOrder },
            {
                preserveScroll: true,
                onError: () => setOrder(previous),
            },
        );
    }

    function moveUp(stageId: string) {
        const index = currentOrder.indexOf(stageId);
        if (index <= 0) {
            return;
        }
        const next = [...currentOrder];
        [next[index - 1], next[index]] = [next[index], next[index - 1]];
        commitOrder(next);
    }

    function moveDown(stageId: string) {
        const index = currentOrder.indexOf(stageId);
        if (index === -1 || index >= currentOrder.length - 1) {
            return;
        }
        const next = [...currentOrder];
        [next[index + 1], next[index]] = [next[index], next[index + 1]];
        commitOrder(next);
    }

    function handleDrop(targetId: string) {
        return () => {
            if (!draggedId || draggedId === targetId) {
                setDraggedId(null);
                return;
            }

            const next = [...currentOrder];
            const fromIndex = next.indexOf(draggedId);
            const toIndex = next.indexOf(targetId);
            next.splice(fromIndex, 1);
            next.splice(toIndex, 0, draggedId);
            commitOrder(next);
            setDraggedId(null);
        };
    }

    function allowDrop(event: DragEvent<HTMLTableRowElement>) {
        event.preventDefault();
    }

    function confirmDelete(stageId: string) {
        setDeleting(true);
        router.delete(`${stagesPath}/${stageId}`, {
            preserveScroll: true,
            onFinish: () => {
                setDeleting(false);
                setConfirmingDeleteId(null);
            },
        });
    }

    const confirmingStage = confirmingDeleteId ? (stagesById.get(confirmingDeleteId) ?? null) : null;

    return (
        <>
            <Head title="Pipeline" />

            <div className="flex flex-col gap-6">
                <PageHeader
                    title="Pipeline stages"
                    description={
                        <>
                            Stages of the <span className="font-medium text-text">{pipelineName}</span> pipeline. Won
                            and Lost are terminal — at most one of each.
                        </>
                    }
                />

                {orderedStages.length === 0 ? (
                    <EmptyState
                        message="This pipeline has no stages yet."
                        action={can.manage ? <span className="text-xs text-text-3">Add the first one below.</span> : undefined}
                    />
                ) : (
                    <div className="overflow-x-auto rounded-lg border border-border">
                        <table className="w-full text-left text-sm">
                            <thead className="bg-raised text-xs font-medium uppercase tracking-wide text-text-2">
                                <tr>
                                    {can.manage && (
                                        <th scope="col" className="w-24 px-3 py-2">
                                            Order
                                        </th>
                                    )}
                                    <th scope="col" className="px-3 py-2">
                                        Stage
                                    </th>
                                    <th scope="col" className="px-3 py-2">
                                        Outcome
                                    </th>
                                    <th scope="col" className="px-3 py-2 text-right">
                                        Probability
                                    </th>
                                    <th scope="col" className="px-3 py-2 text-right">
                                        Deals
                                    </th>
                                    {can.manage && (
                                        <th scope="col" className="px-3 py-2 text-right">
                                            Actions
                                        </th>
                                    )}
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-border-soft">
                                {orderedStages.map((stage, index) => (
                                    <StageRow
                                        key={stage.id}
                                        stage={stage}
                                        canManage={can.manage}
                                        isFirst={index === 0}
                                        isLast={index === orderedStages.length - 1}
                                        isEditing={editingId === stage.id}
                                        onStartEditing={() => setEditingId(stage.id)}
                                        onStopEditing={() => setEditingId(null)}
                                        onMoveUp={() => moveUp(stage.id)}
                                        onMoveDown={() => moveDown(stage.id)}
                                        onRequestDelete={() => setConfirmingDeleteId(stage.id)}
                                        onDragStart={() => setDraggedId(stage.id)}
                                        onDragOver={allowDrop}
                                        onDrop={handleDrop(stage.id)}
                                        stagesPath={stagesPath}
                                    />
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                {can.manage && <AddStageForm stagesPath={stagesPath} />}
            </div>

            <ConfirmDialog
                open={confirmingStage !== null}
                title={confirmingStage ? `Delete "${confirmingStage.name}"?` : ''}
                onClose={() => setConfirmingDeleteId(null)}
                // BR-DEAL-01: dacă etapa are deals, dialogul doar INFORMEAZĂ (fără
                // `onConfirm`) — butonul „Delete" rămâne prezent pentru cine are dreptul
                // (`pipelines.manage`), regula de stare se explică, nu se ascunde.
                onConfirm={confirmingStage && !confirmingStage.deletionBlockedReason ? () => confirmDelete(confirmingStage.id) : undefined}
                confirmLabel="Delete"
                confirmVariant="danger"
                processing={deleting}
            >
                {confirmingStage?.deletionBlockedReason ?? 'This cannot be undone.'}
            </ConfirmDialog>
        </>
    );
}

PipelineIndex.layout = (page: ReactNode) => <AppLayout>{page}</AppLayout>;

interface StageRowProps {
    stage: PipelineStage;
    canManage: boolean;
    isFirst: boolean;
    isLast: boolean;
    isEditing: boolean;
    onStartEditing: () => void;
    onStopEditing: () => void;
    onMoveUp: () => void;
    onMoveDown: () => void;
    onRequestDelete: () => void;
    onDragStart: () => void;
    onDragOver: (event: DragEvent<HTMLTableRowElement>) => void;
    onDrop: () => void;
    stagesPath: string;
}

function StageRow({
    stage,
    canManage,
    isFirst,
    isLast,
    isEditing,
    onStartEditing,
    onStopEditing,
    onMoveUp,
    onMoveDown,
    onRequestDelete,
    onDragStart,
    onDragOver,
    onDrop,
    stagesPath,
}: StageRowProps) {
    const editForm = useForm({
        name: stage.name,
        probability: stage.probability !== null ? String(stage.probability) : '',
        is_won: stage.isWon,
        is_lost: stage.isLost,
    });

    function startEditing() {
        editForm.clearErrors();
        editForm.setData({
            name: stage.name,
            probability: stage.probability !== null ? String(stage.probability) : '',
            is_won: stage.isWon,
            is_lost: stage.isLost,
        });
        onStartEditing();
    }

    function submitEdit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();

        editForm.transform((data) => ({
            ...data,
            probability: data.probability === '' ? null : Number(data.probability),
        }));

        editForm.patch(`${stagesPath}/${stage.id}`, {
            preserveScroll: true,
            onSuccess: () => onStopEditing(),
        });
    }

    if (isEditing) {
        return (
            <tr className="bg-raised">
                <td colSpan={canManage ? 6 : 4} className="px-3 py-3">
                    <form onSubmit={submitEdit} className="flex flex-wrap items-end gap-3" noValidate>
                        <div className="w-48">
                            <Field label="Name" error={editForm.errors.name} required>
                                {(control) => (
                                    <input
                                        {...control}
                                        type="text"
                                        value={editForm.data.name}
                                        onChange={(event) => editForm.setData('name', event.target.value)}
                                        className={controlClass}
                                    />
                                )}
                            </Field>
                        </div>

                        <div className="w-32">
                            <Field label="Probability %" error={editForm.errors.probability} hint="0–100, optional">
                                {(control) => (
                                    <input
                                        {...control}
                                        type="number"
                                        min={0}
                                        max={100}
                                        value={editForm.data.probability}
                                        onChange={(event) => editForm.setData('probability', event.target.value)}
                                        className={controlClass}
                                    />
                                )}
                            </Field>
                        </div>

                        <label className="flex items-center gap-2 text-sm text-text-2">
                            <input
                                type="checkbox"
                                checked={editForm.data.is_won}
                                onChange={(event) => editForm.setData('is_won', event.target.checked)}
                                className="rounded border-control focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus"
                            />
                            Won
                        </label>
                        {editForm.errors.is_won && (
                            <p role="alert" className="text-xs text-danger">
                                {editForm.errors.is_won}
                            </p>
                        )}

                        <label className="flex items-center gap-2 text-sm text-text-2">
                            <input
                                type="checkbox"
                                checked={editForm.data.is_lost}
                                onChange={(event) => editForm.setData('is_lost', event.target.checked)}
                                className="rounded border-control focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus"
                            />
                            Lost
                        </label>
                        {editForm.errors.is_lost && (
                            <p role="alert" className="text-xs text-danger">
                                {editForm.errors.is_lost}
                            </p>
                        )}

                        <div className="flex gap-2">
                            <Button variant="primary" type="submit" disabled={editForm.processing}>
                                Save
                            </Button>
                            <Button type="button" onClick={onStopEditing}>
                                Cancel
                            </Button>
                        </div>
                    </form>
                </td>
            </tr>
        );
    }

    return (
        <tr
            draggable={canManage}
            onDragStart={canManage ? onDragStart : undefined}
            onDragOver={canManage ? onDragOver : undefined}
            onDrop={canManage ? onDrop : undefined}
            className="text-text"
        >
            {canManage && (
                <td className="px-3 py-2">
                    <div className="flex items-center gap-1">
                        <span aria-hidden="true" className="cursor-grab px-1 text-text-3">
                            ⠿
                        </span>
                        <button
                            type="button"
                            onClick={onMoveUp}
                            disabled={isFirst}
                            aria-label={`Move ${stage.name} up`}
                            className="rounded-md px-1.5 py-0.5 text-text-2 hover:bg-row-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus disabled:cursor-not-allowed disabled:opacity-40"
                        >
                            <span aria-hidden="true">↑</span>
                        </button>
                        <button
                            type="button"
                            onClick={onMoveDown}
                            disabled={isLast}
                            aria-label={`Move ${stage.name} down`}
                            className="rounded-md px-1.5 py-0.5 text-text-2 hover:bg-row-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus disabled:cursor-not-allowed disabled:opacity-40"
                        >
                            <span aria-hidden="true">↓</span>
                        </button>
                    </div>
                </td>
            )}
            <td className="px-3 py-2 font-medium">{stage.name}</td>
            <td className="px-3 py-2">
                {stage.isWon && <StatusBadge tone="success">Won</StatusBadge>}
                {stage.isLost && <StatusBadge tone="danger">Lost</StatusBadge>}
                {!stage.isWon && !stage.isLost && <span className="text-text-3">—</span>}
            </td>
            <td className="px-3 py-2 text-right [font-variant-numeric:tabular-nums]">
                {stage.probability === null ? '—' : `${stage.probability}%`}
            </td>
            <td className="px-3 py-2 text-right [font-variant-numeric:tabular-nums]">{stage.dealsCount}</td>
            {canManage && (
                <td className="px-3 py-2">
                    <div className="flex justify-end gap-2">
                        <Button onClick={startEditing}>Edit</Button>
                        {stage.canDelete && (
                            <Button variant="danger" onClick={onRequestDelete}>
                                Delete
                            </Button>
                        )}
                    </div>
                </td>
            )}
        </tr>
    );
}

function AddStageForm({ stagesPath }: { stagesPath: string }) {
    const form = useForm({
        name: '',
        probability: '',
        is_won: false as boolean,
        is_lost: false as boolean,
    });

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();

        form.transform((data) => ({
            ...data,
            probability: data.probability === '' ? null : Number(data.probability),
        }));

        form.post(stagesPath, {
            preserveScroll: true,
            onSuccess: () => form.reset(),
        });
    }

    return (
        <section className="rounded-lg border border-border bg-surface p-4" aria-label="Add stage">
            <h2 className="text-sm font-medium text-text-2">Add stage</h2>
            <form onSubmit={submit} className="mt-3 flex flex-wrap items-end gap-3" noValidate>
                <div className="w-48">
                    <Field label="Name" error={form.errors.name} required>
                        {(control) => (
                            <input
                                {...control}
                                type="text"
                                value={form.data.name}
                                onChange={(event) => form.setData('name', event.target.value)}
                                className={controlClass}
                            />
                        )}
                    </Field>
                </div>

                <div className="w-32">
                    <Field label="Probability %" error={form.errors.probability} hint="0–100, optional">
                        {(control) => (
                            <input
                                {...control}
                                type="number"
                                min={0}
                                max={100}
                                value={form.data.probability}
                                onChange={(event) => form.setData('probability', event.target.value)}
                                className={controlClass}
                            />
                        )}
                    </Field>
                </div>

                <label className="flex items-center gap-2 text-sm text-text-2">
                    <input
                        type="checkbox"
                        checked={form.data.is_won}
                        onChange={(event) => form.setData('is_won', event.target.checked)}
                        className="rounded border-control focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus"
                    />
                    Won
                </label>
                {form.errors.is_won && (
                    <p role="alert" className="text-xs text-danger">
                        {form.errors.is_won}
                    </p>
                )}

                <label className="flex items-center gap-2 text-sm text-text-2">
                    <input
                        type="checkbox"
                        checked={form.data.is_lost}
                        onChange={(event) => form.setData('is_lost', event.target.checked)}
                        className="rounded border-control focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus"
                    />
                    Lost
                </label>
                {form.errors.is_lost && (
                    <p role="alert" className="text-xs text-danger">
                        {form.errors.is_lost}
                    </p>
                )}

                <Button variant="primary" type="submit" disabled={form.processing}>
                    Add stage
                </Button>
            </form>
        </section>
    );
}
