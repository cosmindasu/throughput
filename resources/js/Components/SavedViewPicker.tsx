import { Link, usePage } from '@inertiajs/react';
import { useEffect, useId, useRef, useState, type FormEvent, type KeyboardEvent as ReactKeyboardEvent } from 'react';
import { useTranslation } from 'react-i18next';
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
    const { t } = useTranslation('common');
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
                {/* `active.name` e conținut scris de utilizator (FR-I18N-06) — niciodată tradus. */}
                <span className="max-w-[12rem] truncate">{active ? active.name : t('common:savedViews.trigger')}</span>
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
                        <p className="px-2 py-3 text-text-3">{t('common:states.loading')}</p>
                    ) : (
                        <>
                            <SavedViewGroup
                                title={t('common:savedViews.myViews')}
                                emptyMessage={t('common:savedViews.noPrivateViews')}
                                views={data.mine}
                                activeId={active?.id ?? null}
                                defaultId={data.defaultId}
                                applyHref={(view) => `${base}/saved-views/${view.id}/apply`}
                                onRename={setRenaming}
                                onDelete={setPendingDelete}
                                onSetDefault={setDefault}
                            />
                            <SavedViewGroup
                                title={t('common:savedViews.teamViews')}
                                emptyMessage={t('common:savedViews.noTeamViews')}
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
                            {t('common:savedViews.saveView')}
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
                // `pendingDelete.name` e conținut scris de utilizator (FR-I18N-06) —
                // interpolat, nu tradus.
                title={t('common:savedViews.deleteTitle', { name: pendingDelete?.name ?? '' })}
                onConfirm={deleteView}
                confirmLabel={t('common:actions.delete')}
                confirmVariant="danger"
                processing={deleting}
                onClose={() => setPendingDelete(null)}
            >
                {pendingDelete?.visibility === 'team'
                    ? t('common:savedViews.deleteBodyTeam')
                    : t('common:savedViews.deleteBodyPrivate')}
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
    const { t } = useTranslation('common');

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

                                {/* SC 2.4.4 / 4.1.2 — „Set default"/„Rename"/„Delete" se repetă
                                    identic pe fiecare vedere salvată din meniu. Discriminatorul e
                                    `sr-only` DUPĂ textul vizibil, deci numele accesibil ÎNCEPE tot
                                    cu el (SC 2.5.3 Label in Name). `title` rămâne ce era: un
                                    tooltip de mouse, nu numele accesibil — textul îl are deja. */}
                                {/* `view.name` e conținut scris de utilizator (FR-I18N-06) — folosit
                                    doar ca discriminator `sr-only`, niciodată tradus. */}
                                <div className="flex shrink-0 items-center gap-2 text-xs text-text-3">
                                    <button
                                        type="button"
                                        aria-pressed={isDefault}
                                        onClick={() => onSetDefault(isDefault ? null : view)}
                                        className={`rounded hover:text-text ${isDefault ? 'text-accent-text' : ''}`}
                                        title={isDefault ? t('common:savedViews.removeDefaultTooltip') : t('common:savedViews.setDefaultTooltip')}
                                    >
                                        {isDefault ? t('common:savedViews.defaultLabel') : t('common:savedViews.setDefaultLabel')}
                                        <span className="sr-only"> — {view.name}</span>
                                    </button>
                                    {view.canUpdate && (
                                        <button type="button" onClick={() => onRename(view)} className="rounded hover:text-text">
                                            {t('common:actions.rename')}
                                            <span className="sr-only"> {view.name}</span>
                                        </button>
                                    )}
                                    {view.canDelete && (
                                        <button type="button" onClick={() => onDelete(view)} className="rounded hover:text-danger">
                                            {t('common:actions.delete')}
                                            <span className="sr-only"> {view.name}</span>
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
    const { t } = useTranslation('common');
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

        // Butonul de submit e `aria-disabled`, nu `disabled` nativ (`Button`, prop
        // `pending`) — al doilea submit se oprește AICI, nu de browser.
        if (processing) {
            return;
        }

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
            setError(caught instanceof ApiError ? caught.message : t('common:savedViews.saveError'));
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
                    {t('common:savedViews.saveView')}
                </h2>

                <div className="mt-3 flex flex-col gap-1">
                    <label htmlFor={`${titleId}-name`} className="text-sm font-medium text-text">
                        {t('common:savedViews.nameLabel')}
                    </label>
                    <input
                        id={`${titleId}-name`}
                        type="text"
                        required
                        maxLength={120}
                        value={name}
                        onChange={(event) => setName(event.target.value)}
                        className={controlClass}
                        placeholder={t('common:savedViews.namePlaceholder')}
                    />
                </div>

                <fieldset className="mt-3">
                    <legend className="text-sm font-medium text-text">{t('common:savedViews.visibilityLegend')}</legend>
                    <div className="mt-1 flex flex-col gap-1 text-sm">
                        <label className="flex items-center gap-2">
                            <input
                                type="radio"
                                name="visibility"
                                checked={visibility === 'private'}
                                onChange={() => setVisibility('private')}
                                className="focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus"
                            />
                            {t('common:savedViews.visibilityPrivate')}
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
                                {t('common:savedViews.visibilityTeam')}
                            </label>
                        )}
                    </div>
                </fieldset>

                {/* `labelize(key)` rămâne netradus — cheile de filtru vin din patru tipuri
                    de resurse diferite (accounts/deals/orders/products), fără un catalog de
                    etichete per câmp în perimetrul acestui val; semnalat în raport. */}
                <div className="mt-3 rounded-md bg-raised px-3 py-2 text-xs text-text-2">
                    <p className="font-medium text-text-3 uppercase">{t('common:savedViews.currentFilters')}</p>
                    {filterEntries.length === 0 ? (
                        <p className="mt-1">{t('common:savedViews.noFilters', { sort: current.sort })}</p>
                    ) : (
                        <ul className="mt-1 flex flex-col gap-0.5">
                            {filterEntries.map(([key, value]) => (
                                <li key={key}>
                                    {labelize(key)}: {value}
                                </li>
                            ))}
                            <li>{t('common:savedViews.sortLabel', { sort: current.sort })}</li>
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
                        {t('common:actions.cancel')}
                    </Button>
                    <Button type="submit" variant="primary" pending={processing} pendingLabel={t('common:actions.saving')}>
                        {t('common:actions.save')}
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
    const { t } = useTranslation('common');
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
        if (!view || processing) {
            return;
        }

        setProcessing(true);
        setError(null);

        try {
            await api.patch(`${base}/saved-views/${view.id}`, { name });
            onRenamed();
        } catch (caught) {
            setError(caught instanceof ApiError ? caught.message : t('common:savedViews.renameError'));
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
                    {t('common:savedViews.renameTitle')}
                </h2>

                <div className="mt-3 flex flex-col gap-1">
                    <label htmlFor={`${titleId}-name`} className="text-sm font-medium text-text">
                        {t('common:savedViews.nameLabel')}
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
                        {t('common:actions.cancel')}
                    </Button>
                    <Button type="submit" variant="primary" pending={processing} pendingLabel={t('common:actions.renaming')}>
                        {t('common:actions.rename')}
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
