import { Link, usePage } from '@inertiajs/react';
import { useEffect, useId, useRef, useState, type FormEvent, type KeyboardEvent as ReactKeyboardEvent } from 'react';
import Button from '@/Components/Button';
import ConfirmDialog from '@/Components/ConfirmDialog';
import { controlClass } from '@/Components/Form/Field';
import { api, ApiError } from '@/lib/api';
import type { ListState, SavedViewsIndexResponse, SavedViewSummary, SavedViewVisibility } from '@/types/generated';

interface SavedViewPickerProps {
    /** Doar resursele din `App\Support\SavedViews\SavedViewResourceType::supported()`. */
    resourceType: 'accounts' | 'deals' | 'orders' | 'products';
    /** Starea curentă a listei (`list`/`filters`, după pagină) — `ListQuery::toArray()`. */
    current: ListState;
    /** Coloanele EFECTIVE curente ale paginii (specs.md §15.1) — propul `columns`, validat server-side. */
    columns: string[];
}

/**
 * Vizualizări salvate — specs.md §15, FR-VIEW-01/02, US-VIEW-01/02. Montat pe
 * `Accounts/Index` și `Deals/Index` cu propul `current` = starea curentă a URL-ului
 * (§15.2 — sursa de adevăr rămâne acolo, nu în acest component: „Apply" e un `<Link>`
 * obișnuit către `/saved-views/{id}/apply`, nu o mutație de stare locală).
 *
 * Tipar de disclosure (buton + panou), NU „menu button"/"listbox" din
 * `WorkspaceSwitcher.tsx`: fiecare rând are AICI acțiuni secundare (Rename/Delete/Set as
 * default) pe lângă selecția principală, iar WAI-ARIA interzice descendenți focusabili în
 * interiorul unui `role="option"`/`role="menuitem"`. Panoul conține deci elemente native
 * (`<a>`/`<button>`), Tab-navigabile în ordinea din DOM — Escape închide și readuce
 * focusul pe declanșator, click în afara panoului la fel (ca la `WorkspaceSwitcher`).
 */
export default function SavedViewPicker({ resourceType, current, columns }: SavedViewPickerProps) {
    const { workspace } = usePage().props;
    const base = workspace ? `/${workspace.slug}` : '';

    const [open, setOpen] = useState(false);
    const [data, setData] = useState<SavedViewsIndexResponse | null>(null);
    const [saveDialogOpen, setSaveDialogOpen] = useState(false);
    const [renaming, setRenaming] = useState<SavedViewSummary | null>(null);
    const [pendingDelete, setPendingDelete] = useState<SavedViewSummary | null>(null);
    const [deleting, setDeleting] = useState(false);

    const buttonRef = useRef<HTMLButtonElement>(null);
    const panelRef = useRef<HTMLDivElement>(null);
    const panelId = useId();

    const load = () => {
        if (!workspace) {
            return;
        }

        api.get<SavedViewsIndexResponse>(`${base}/saved-views/${resourceType}`)
            .then(setData)
            .catch(() => setData({ mine: [], team: [], defaultId: null, can: { createTeam: false } }));
    };

    // eslint-disable-next-line react-hooks/exhaustive-deps -- `base` derivă din `workspace`, stabil per montare a paginii.
    useEffect(load, [resourceType]);

    useEffect(() => {
        if (!open) {
            return;
        }

        const onPointerDown = (event: MouseEvent) => {
            const target = event.target as Node;
            if (!panelRef.current?.contains(target) && !buttonRef.current?.contains(target)) {
                setOpen(false);
            }
        };

        document.addEventListener('mousedown', onPointerDown);
        return () => document.removeEventListener('mousedown', onPointerDown);
    }, [open]);

    const closePanel = (focusButton = true) => {
        setOpen(false);
        if (focusButton) {
            buttonRef.current?.focus();
        }
    };

    const onPanelKeyDown = (event: ReactKeyboardEvent<HTMLDivElement>) => {
        if (event.key === 'Escape') {
            event.preventDefault();
            closePanel();
        }
    };

    const all = data ? [...data.mine, ...data.team] : [];
    const active = all.find((view) => sameListState(current, columns, view)) ?? null;

    const refreshAfterMutation = () => {
        load();
    };

    const setDefault = async (view: SavedViewSummary | null) => {
        const response = await api.put<{ defaultId: string | null }>(`${base}/saved-views/${resourceType}/default`, {
            saved_view_id: view?.id ?? null,
        });
        setData((previous) => (previous ? { ...previous, defaultId: response.defaultId } : previous));
    };

    const deleteView = async () => {
        if (!pendingDelete) {
            return;
        }

        setDeleting(true);
        try {
            await api.delete(`${base}/saved-views/${pendingDelete.id}`);
            setPendingDelete(null);
            refreshAfterMutation();
        } finally {
            setDeleting(false);
        }
    };

    return (
        <div className="relative">
            <button
                ref={buttonRef}
                type="button"
                aria-expanded={open}
                aria-controls={panelId}
                onClick={() => (open ? closePanel() : setOpen(true))}
                className="flex items-center gap-2 rounded-md border border-control px-3 py-1.5 text-sm text-text transition-colors hover:bg-row-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus"
            >
                <span className="max-w-[12rem] truncate">{active ? active.name : 'Views'}</span>
                <span aria-hidden="true">▾</span>
            </button>

            {open && (
                <div
                    id={panelId}
                    ref={panelRef}
                    onKeyDown={onPanelKeyDown}
                    className="absolute right-0 z-20 mt-1 w-80 rounded-md border border-border bg-overlay p-2 text-sm shadow-lg"
                >
                    {data === null ? (
                        <p className="px-2 py-3 text-text-3">Loading…</p>
                    ) : (
                        <>
                            <SavedViewGroup
                                title="My views"
                                emptyMessage="No private views yet."
                                views={data.mine}
                                activeId={active?.id ?? null}
                                defaultId={data.defaultId}
                                applyHref={(view) => `${base}/saved-views/${view.id}/apply`}
                                onRename={setRenaming}
                                onDelete={setPendingDelete}
                                onSetDefault={setDefault}
                            />
                            <SavedViewGroup
                                title="Team views"
                                emptyMessage="No team views yet."
                                views={data.team}
                                activeId={active?.id ?? null}
                                defaultId={data.defaultId}
                                applyHref={(view) => `${base}/saved-views/${view.id}/apply`}
                                onRename={setRenaming}
                                onDelete={setPendingDelete}
                                onSetDefault={setDefault}
                            />
                        </>
                    )}

                    <div className="mt-2 border-t border-border-soft pt-2">
                        <Button
                            variant="secondary"
                            className="w-full justify-center"
                            onClick={() => {
                                closePanel(false);
                                setSaveDialogOpen(true);
                            }}
                        >
                            Save view
                        </Button>
                    </div>
                </div>
            )}

            <SaveViewDialog
                open={saveDialogOpen}
                resourceType={resourceType}
                base={base}
                current={current}
                columns={columns}
                canCreateTeam={data?.can.createTeam ?? false}
                onClose={() => setSaveDialogOpen(false)}
                onSaved={() => {
                    setSaveDialogOpen(false);
                    refreshAfterMutation();
                }}
            />

            <RenameViewDialog
                view={renaming}
                base={base}
                onClose={() => setRenaming(null)}
                onRenamed={() => {
                    setRenaming(null);
                    refreshAfterMutation();
                }}
            />

            <ConfirmDialog
                open={pendingDelete !== null}
                title={`Delete "${pendingDelete?.name ?? ''}"?`}
                onConfirm={deleteView}
                confirmLabel="Delete"
                confirmVariant="danger"
                processing={deleting}
                onClose={() => setPendingDelete(null)}
            >
                {pendingDelete?.visibility === 'team'
                    ? 'This removes the view for everyone on the team who can see it. This can’t be undone.'
                    : 'This can’t be undone.'}
            </ConfirmDialog>
        </div>
    );
}

function sameListState(current: ListState, columns: string[], view: SavedViewSummary): boolean {
    if (current.sort !== view.sort) {
        return false;
    }

    const currentKeys = Object.keys(current.filter);
    const viewKeys = Object.keys(view.filter);

    if (currentKeys.length !== viewKeys.length) {
        return false;
    }

    if (!currentKeys.every((key) => current.filter[key] === view.filter[key])) {
        return false;
    }

    // Selector de coloane (§15.1) — „activă" înseamnă TOATĂ starea identică, inclusiv
    // ordinea coloanelor, nu doar filtrele/sortarea.
    return columns.length === view.columns.length && columns.every((key, index) => key === view.columns[index]);
}

interface SavedViewGroupProps {
    title: string;
    emptyMessage: string;
    views: SavedViewSummary[];
    activeId: string | null;
    defaultId: string | null;
    applyHref: (view: SavedViewSummary) => string;
    onRename: (view: SavedViewSummary) => void;
    onDelete: (view: SavedViewSummary) => void;
    onSetDefault: (view: SavedViewSummary | null) => void;
}

function SavedViewGroup({ title, emptyMessage, views, activeId, defaultId, applyHref, onRename, onDelete, onSetDefault }: SavedViewGroupProps) {
    return (
        <div className="mb-2">
            <p className="px-2 pt-1 pb-1 text-xs font-medium tracking-wide text-text-3 uppercase">{title}</p>

            {views.length === 0 ? (
                <p className="px-2 pb-1 text-xs text-text-3">{emptyMessage}</p>
            ) : (
                <ul className="flex flex-col">
                    {views.map((view) => {
                        const isDefault = defaultId === view.id;

                        return (
                            <li key={view.id} className="flex items-center justify-between gap-2 rounded px-2 py-1.5 hover:bg-row-hover">
                                <Link
                                    href={applyHref(view)}
                                    aria-current={activeId === view.id ? 'true' : undefined}
                                    className={`truncate ${activeId === view.id ? 'font-medium text-accent-text' : 'text-text'}`}
                                >
                                    {view.name}
                                </Link>

                                <div className="flex shrink-0 items-center gap-2 text-xs text-text-3">
                                    <button
                                        type="button"
                                        aria-pressed={isDefault}
                                        onClick={() => onSetDefault(isDefault ? null : view)}
                                        className={`hover:text-text ${isDefault ? 'text-accent-text' : ''}`}
                                        title={isDefault ? 'Remove as default' : 'Set as default'}
                                    >
                                        {isDefault ? '★ Default' : '☆ Set default'}
                                    </button>
                                    {view.canUpdate && (
                                        <button type="button" onClick={() => onRename(view)} className="hover:text-text">
                                            Rename
                                        </button>
                                    )}
                                    {view.canDelete && (
                                        <button type="button" onClick={() => onDelete(view)} className="hover:text-danger">
                                            Delete
                                        </button>
                                    )}
                                </div>
                            </li>
                        );
                    })}
                </ul>
            )}
        </div>
    );
}

interface SaveViewDialogProps {
    open: boolean;
    resourceType: string;
    base: string;
    current: ListState;
    columns: string[];
    canCreateTeam: boolean;
    onClose: () => void;
    onSaved: () => void;
}

/**
 * FR-VIEW-01 — pornește din starea curentă a URL-ului: `current` e propul `list`/`filters`
 * al paginii, deja canonic (`ListQuery::toArray()`), afișat aici doar ca REZUMAT — serverul
 * revalidează totul prin `ResourceList::fromState()` la salvare (§ StoreSavedViewRequest).
 */
function SaveViewDialog({ open, resourceType, base, current, columns, canCreateTeam, onClose, onSaved }: SaveViewDialogProps) {
    const dialogRef = useRef<HTMLDialogElement>(null);
    const titleId = useId();
    const [name, setName] = useState('');
    const [visibility, setVisibility] = useState<SavedViewVisibility>('private');
    const [processing, setProcessing] = useState(false);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        const dialog = dialogRef.current;
        if (!dialog) {
            return;
        }

        if (open && !dialog.open) {
            setName('');
            setVisibility('private');
            setError(null);
            dialog.showModal();
        } else if (!open && dialog.open) {
            dialog.close();
        }
    }, [open]);

    const submit = async (event: FormEvent) => {
        event.preventDefault();
        setProcessing(true);
        setError(null);

        try {
            await api.post(`${base}/saved-views`, {
                resource_type: resourceType,
                name,
                visibility,
                filter: current.filter,
                sort: current.sort,
                columns,
            });
            onSaved();
        } catch (caught) {
            setError(caught instanceof ApiError ? caught.message : 'Could not save this view.');
        } finally {
            setProcessing(false);
        }
    };

    const filterEntries = Object.entries(current.filter);

    return (
        <dialog
            ref={dialogRef}
            aria-labelledby={titleId}
            onClose={onClose}
            className="m-auto rounded-lg border border-border bg-overlay p-0 text-text backdrop:bg-scrim"
        >
            <form onSubmit={submit} className="w-[min(26rem,90vw)] p-5">
                <h2 id={titleId} className="text-base font-semibold text-text">
                    Save view
                </h2>

                <div className="mt-3 flex flex-col gap-1">
                    <label htmlFor={`${titleId}-name`} className="text-sm font-medium text-text">
                        Name
                    </label>
                    <input
                        id={`${titleId}-name`}
                        type="text"
                        required
                        maxLength={120}
                        value={name}
                        onChange={(event) => setName(event.target.value)}
                        className={controlClass}
                        placeholder="e.g. My open deals closing this month"
                    />
                </div>

                <fieldset className="mt-3">
                    <legend className="text-sm font-medium text-text">Visibility</legend>
                    <div className="mt-1 flex flex-col gap-1 text-sm">
                        <label className="flex items-center gap-2">
                            <input
                                type="radio"
                                name="visibility"
                                checked={visibility === 'private'}
                                onChange={() => setVisibility('private')}
                                className="focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus"
                            />
                            Private — only you
                        </label>
                        {canCreateTeam && (
                            <label className="flex items-center gap-2">
                                <input
                                    type="radio"
                                    name="visibility"
                                    checked={visibility === 'team'}
                                    onChange={() => setVisibility('team')}
                                    className="focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus"
                                />
                                Team — everyone in this workspace
                            </label>
                        )}
                    </div>
                </fieldset>

                <div className="mt-3 rounded-md bg-raised px-3 py-2 text-xs text-text-2">
                    <p className="font-medium text-text-3 uppercase">Current filters</p>
                    {filterEntries.length === 0 ? (
                        <p className="mt-1">No filters — sort: {current.sort}</p>
                    ) : (
                        <ul className="mt-1 flex flex-col gap-0.5">
                            {filterEntries.map(([key, value]) => (
                                <li key={key}>
                                    {labelize(key)}: {value}
                                </li>
                            ))}
                            <li>Sort: {current.sort}</li>
                        </ul>
                    )}
                </div>

                {error && (
                    <p role="alert" className="mt-3 text-sm text-danger">
                        {error}
                    </p>
                )}

                <div className="mt-5 flex justify-end gap-2">
                    <Button type="button" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button type="submit" variant="primary" disabled={processing}>
                        Save
                    </Button>
                </div>
            </form>
        </dialog>
    );
}

interface RenameViewDialogProps {
    view: SavedViewSummary | null;
    base: string;
    onClose: () => void;
    onRenamed: () => void;
}

function RenameViewDialog({ view, base, onClose, onRenamed }: RenameViewDialogProps) {
    const dialogRef = useRef<HTMLDialogElement>(null);
    const titleId = useId();
    const [name, setName] = useState('');
    const [processing, setProcessing] = useState(false);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        const dialog = dialogRef.current;
        if (!dialog) {
            return;
        }

        if (view && !dialog.open) {
            setName(view.name);
            setError(null);
            dialog.showModal();
        } else if (!view && dialog.open) {
            dialog.close();
        }
    }, [view]);

    const submit = async (event: FormEvent) => {
        event.preventDefault();
        if (!view) {
            return;
        }

        setProcessing(true);
        setError(null);

        try {
            await api.patch(`${base}/saved-views/${view.id}`, { name });
            onRenamed();
        } catch (caught) {
            setError(caught instanceof ApiError ? caught.message : 'Could not rename this view.');
        } finally {
            setProcessing(false);
        }
    };

    return (
        <dialog
            ref={dialogRef}
            aria-labelledby={titleId}
            onClose={onClose}
            className="m-auto rounded-lg border border-border bg-overlay p-0 text-text backdrop:bg-scrim"
        >
            <form onSubmit={submit} className="w-[min(22rem,90vw)] p-5">
                <h2 id={titleId} className="text-base font-semibold text-text">
                    Rename view
                </h2>

                <div className="mt-3 flex flex-col gap-1">
                    <label htmlFor={`${titleId}-name`} className="text-sm font-medium text-text">
                        Name
                    </label>
                    <input
                        id={`${titleId}-name`}
                        type="text"
                        required
                        maxLength={120}
                        value={name}
                        onChange={(event) => setName(event.target.value)}
                        className={controlClass}
                    />
                </div>

                {error && (
                    <p role="alert" className="mt-3 text-sm text-danger">
                        {error}
                    </p>
                )}

                <div className="mt-5 flex justify-end gap-2">
                    <Button type="button" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button type="submit" variant="primary" disabled={processing}>
                        Rename
                    </Button>
                </div>
            </form>
        </dialog>
    );
}

function labelize(key: string): string {
    const spaced = key.replace(/_/g, ' ');

    return spaced.charAt(0).toUpperCase() + spaced.slice(1);
}
