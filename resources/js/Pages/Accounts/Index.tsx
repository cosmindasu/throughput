import { Deferred, Head, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import BulkSelectionBar from '@/Components/BulkSelectionBar';
import Button, { ButtonLink, buttonClass } from '@/Components/Button';
import CursorPagination from '@/Components/CursorPagination';
import EmptyState from '@/Components/EmptyState';
import PageHeader from '@/Components/PageHeader';
import SavedViewPicker from '@/Components/SavedViewPicker';
import StatusBadge from '@/Components/StatusBadge';
import TableSkeleton from '@/Components/TableSkeleton';
import { useBulkSelection } from '@/hooks/useBulkSelection';
import { useListFilters } from '@/hooks/useListFilters';
import AppLayout from '@/Layouts/AppLayout';
import type { AccountRow, AccountsIndexPageProps } from '@/types/generated';

/**
 * Accounts/Index — FR-CRM-03, US-CRM-02. `accounts` e deferred (FR-PERF-01): shell-ul
 * (filtre, header) apare instant, rândurile vin după — vezi `TableSkeleton`.
 */
export default function Index() {
    const { accounts, total, list, owners, can, bulkConfirmationThreshold, workspace } = usePage<AccountsIndexPageProps>().props;
    const { url } = usePage();
    const { setFilter, setSort } = useListFilters(list);

    const hasFilters = Object.keys(list.filter).length > 0;
    const base = workspace ? `/${workspace.slug}` : '';
    const exportHref = buildExportHref(url, base);
    const bulkDispatchUrl = buildBulkDispatchUrl(url, base, 'accounts');

    const pageIds = accounts ? accounts.data.map((account) => account.id) : [];
    const selection = useBulkSelection(pageIds);

    return (
        <>
            <Head title="Accounts" />

            <div className="flex flex-col gap-6">
                <PageHeader
                    title="Accounts"
                    actions={
                        <>
                            <SavedViewPicker resourceType="accounts" current={list} />
                            {can.export && (
                                <a href={exportHref} className={buttonClass('secondary')}>
                                    Export CSV
                                </a>
                            )}
                            {can.create && <ButtonLink variant="primary" href={`${base}/accounts/create`}>New account</ButtonLink>}
                        </>
                    }
                />

                <div className="flex flex-wrap items-end gap-3">
                    <label className="flex flex-col gap-1 text-sm text-text-2">
                        Search
                        <input
                            type="search"
                            defaultValue={list.filter.q ?? ''}
                            onChange={(event) => setFilter('q', event.target.value)}
                            placeholder="Account name…"
                            className="rounded-md border border-control bg-surface px-3 py-1.5 text-sm text-text placeholder:text-text-3 focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-focus"
                        />
                    </label>

                    <label className="flex flex-col gap-1 text-sm text-text-2">
                        Owner
                        <select
                            value={list.filter.owner ?? 'all'}
                            onChange={(event) => setFilter('owner', event.target.value)}
                            className="rounded-md border border-control bg-surface px-3 py-1.5 text-sm text-text focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-focus"
                        >
                            <option value="me">My accounts</option>
                            <option value="all">All accounts</option>
                            <option value="unassigned">Unassigned</option>
                            {owners.map((owner) => (
                                <option key={owner.id} value={owner.id}>
                                    {owner.name}
                                </option>
                            ))}
                        </select>
                    </label>

                    <label className="flex flex-col gap-1 text-sm text-text-2">
                        Status
                        <select
                            value={list.filter.status ?? ''}
                            onChange={(event) => setFilter('status', event.target.value || null)}
                            className="rounded-md border border-control bg-surface px-3 py-1.5 text-sm text-text focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-focus"
                        >
                            <option value="">Any status</option>
                            <option value="prospect">Prospect</option>
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </label>

                    <label className="flex flex-col gap-1 text-sm text-text-2">
                        Sort by
                        <select
                            value={list.sort}
                            onChange={(event) => setSort(event.target.value)}
                            className="rounded-md border border-control bg-surface px-3 py-1.5 text-sm text-text focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-focus"
                        >
                            <option value="name">Name</option>
                            <option value="-created_at">Newest</option>
                        </select>
                    </label>

                    {hasFilters && <Button onClick={() => clearAll(setFilter)}>Clear filters</Button>}
                </div>

                {can.bulkWrite && (
                    <BulkSelectionBar
                        resourceNounSingular="account"
                        resourceNounPlural="accounts"
                        dispatchUrl={bulkDispatchUrl}
                        total={total}
                        selectedCount={selection.selectedCount}
                        allOnPageSelected={selection.allOnPageSelected}
                        matchingFilter={selection.matchingFilter}
                        selectedIds={selection.selectedIds}
                        owners={owners}
                        confirmationThreshold={bulkConfirmationThreshold}
                        onSelectAllMatching={selection.selectAllMatching}
                        onClearSelection={selection.clear}
                    />
                )}

                <Deferred data="accounts" fallback={<TableSkeleton columns={5} />}>
                    {accounts && accounts.data.length > 0 ? (
                        <div className="overflow-hidden rounded-lg border border-border">
                            <table className="w-full text-left text-sm">
                                <thead className="bg-raised text-text-2">
                                    <tr>
                                        {can.bulkWrite && (
                                            <th scope="col" className="w-10 px-4 py-2">
                                                <input
                                                    type="checkbox"
                                                    aria-label="Select all accounts on this page"
                                                    checked={selection.allOnPageSelected}
                                                    onChange={selection.toggleAllOnPage}
                                                    className="size-4 rounded border-control"
                                                />
                                            </th>
                                        )}
                                        <th scope="col" className="px-4 py-2 font-medium">Name</th>
                                        <th scope="col" className="px-4 py-2 font-medium">Owner</th>
                                        <th scope="col" className="px-4 py-2 font-medium">Status</th>
                                        <th scope="col" className="px-4 py-2 font-medium">Created</th>
                                        <th scope="col" className="px-4 py-2 font-medium">
                                            <span className="sr-only">Actions</span>
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-border-soft bg-surface">
                                    {accounts.data.map((account: AccountRow) => (
                                        <tr key={account.id} className="hover:bg-row-hover">
                                            {can.bulkWrite && (
                                                <td className="px-4 py-2.5">
                                                    <input
                                                        type="checkbox"
                                                        aria-label={`Select ${account.name}`}
                                                        checked={selection.isSelected(account.id)}
                                                        onChange={() => selection.toggleRow(account.id)}
                                                        className="size-4 rounded border-control"
                                                    />
                                                </td>
                                            )}
                                            <td className="px-4 py-2.5">
                                                <a href={`${base}/accounts/${account.id}`} className="font-medium text-accent-text hover:underline">
                                                    {account.name}
                                                </a>
                                                {account.domain && <div className="text-xs text-text-3">{account.domain}</div>}
                                            </td>
                                            <td className="px-4 py-2.5 text-text-2">{account.owner?.name ?? '—'}</td>
                                            <td className="px-4 py-2.5">
                                                <StatusBadge tone={statusTone(account.status)}>{account.status}</StatusBadge>
                                            </td>
                                            <td className="px-4 py-2.5 tabular-nums text-text-2">
                                                {account.createdAt ? new Date(account.createdAt).toLocaleDateString('en-US') : '—'}
                                            </td>
                                            <td className="px-4 py-2.5 text-right">
                                                {account.canEdit && (
                                                    <a href={`${base}/accounts/${account.id}/edit`} className="text-sm text-accent-text hover:underline">
                                                        Edit
                                                    </a>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    ) : (
                        accounts && (
                            <EmptyState
                                message={hasFilters ? 'No accounts match this filter.' : 'No accounts yet.'}
                                action={
                                    hasFilters ? (
                                        <Button onClick={() => clearAll(setFilter)}>Clear filters</Button>
                                    ) : (
                                        can.create && (
                                            <ButtonLink variant="primary" href={`${base}/accounts/create`}>
                                                Create your first account
                                            </ButtonLink>
                                        )
                                    )
                                }
                            />
                        )
                    )}
                </Deferred>

                {accounts && <CursorPagination nextCursor={accounts.nextCursor} prevCursor={accounts.prevCursor} />}
            </div>
        </>
    );
}

function statusTone(status: string): 'success' | 'warning' | 'neutral' {
    if (status === 'active') {
        return 'success';
    }

    if (status === 'prospect') {
        return 'warning';
    }

    return 'neutral';
}

function clearAll(setFilter: (key: string, value: string | null) => void): void {
    ['q', 'status', 'owner'].forEach((key) => setFilter(key, key === 'owner' ? 'all' : null));
}

function buildExportHref(currentUrl: string, base: string): string {
    const query = currentUrl.split('?')[1];
    const exportPath = `${base}/accounts/export`;

    return query ? `${exportPath}?${query}` : exportPath;
}

/**
 * §13.2 — `DispatchBulkOperationAction` capturează exact filtrul/sortul din querystring-ul
 * curent, ca lista/exportul/operația în masă să vadă aceleași rânduri pentru același URL.
 */
function buildBulkDispatchUrl(currentUrl: string, base: string, resourceType: 'accounts' | 'deals'): string {
    const query = currentUrl.split('?')[1];
    const path = `${base}/${resourceType}/bulk/reassign-owner`;

    return query ? `${path}?${query}` : path;
}

Index.layout = (page: ReactNode) => <AppLayout>{page}</AppLayout>;
