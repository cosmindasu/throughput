import { Deferred, Head, Link, usePage } from '@inertiajs/react';
import { type FormEvent, type ReactNode } from 'react';
import Button, { ButtonLink, buttonClass } from '@/Components/Button';
import CursorPagination from '@/Components/CursorPagination';
import EmptyState from '@/Components/EmptyState';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import TableSkeleton from '@/Components/TableSkeleton';
import { useListFilters } from '@/hooks/useListFilters';
import AppLayout from '@/Layouts/AppLayout';
import type { ContactsIndexPageProps } from '@/types/generated';

const SORT_OPTIONS = [
    { value: 'last_name', label: 'Last name (A–Z)' },
    { value: '-created_at', label: 'Newest first' },
    { value: 'created_at', label: 'Oldest first' },
];

/**
 * Lista de contacte — FR-CRM-03-adiacent (aceeași infrastructură de cursor ca
 * Accounts), fără filtrul implicit „My accounts": citirea e pe tot tenantul
 * (`ContactPolicy::viewAny`), doar editarea se îngustează per rând (`can.edit`).
 */
export default function ContactsIndex() {
    const page = usePage<ContactsIndexPageProps>();
    const { list, can, workspace } = page.props;
    const { url } = page;
    const { setFilter, setSort } = useListFilters(list);

    const submitSearch = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const formData = new FormData(event.currentTarget);
        setFilter('q', String(formData.get('q') ?? ''));
    };

    return (
        <>
            <Head title="Contacts" />

            <div className="flex flex-col gap-6">
                <PageHeader
                    title="Contacts"
                    description="People at your accounts — and leads without one yet."
                    actions={
                        <>
                            {/* Link simplu, nu Inertia: răspunsul e un fișier CSV sau un redirect către
                                pagina exportului în coadă. Exportul e o citire, deci îl are și Viewer-ul
                                (§7.4 nota ³). Query string-ul curent trece neschimbat: exportul conține
                                exact rândurile de pe ecran. */}
                            {can.export && workspace && (
                                <a href={exportHref(url, `/${workspace.slug}/contacts/export`)} className={buttonClass('secondary')}>
                                    Export CSV
                                </a>
                            )}
                            {can.create && (
                                <ButtonLink variant="primary" href={workspace ? `/${workspace.slug}/contacts/create` : '#'}>
                                    New contact
                                </ButtonLink>
                            )}
                        </>
                    }
                />

                <div className="flex flex-wrap items-end justify-between gap-4">
                    <form onSubmit={submitSearch} className="flex flex-wrap items-end gap-3" role="search">
                        <div className="flex flex-col gap-1">
                            <label htmlFor="contacts-search" className="text-sm font-medium text-text">
                                Search
                            </label>
                            <input
                                id="contacts-search"
                                name="q"
                                type="search"
                                defaultValue={list.filter.q ?? ''}
                                placeholder="Name or email"
                                className="w-64 rounded-md border border-control bg-surface px-3 py-1.5 text-sm text-text placeholder:text-text-3 focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-focus"
                            />
                        </div>
                        {/* Primitiva `Button`: aceleași clase, copiate de mână, minus stilul
                            de focus (SC 2.4.7). */}
                        <Button type="submit">Apply</Button>
                        {list.filter.account && (
                            <button
                                type="button"
                                onClick={() => setFilter('account', null)}
                                className="text-sm text-accent-text underline underline-offset-2"
                            >
                                Clear account filter
                            </button>
                        )}
                    </form>

                    <div className="flex flex-col gap-1">
                        <label htmlFor="contacts-sort" className="text-sm font-medium text-text">
                            Sort by
                        </label>
                        <select
                            id="contacts-sort"
                            value={list.sort}
                            onChange={(event) => setSort(event.target.value)}
                            className="rounded-md border border-control bg-surface px-3 py-1.5 text-sm text-text focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-focus"
                        >
                            {SORT_OPTIONS.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </select>
                    </div>
                </div>

                <Deferred data="contacts" fallback={<TableSkeleton columns={5} />}>
                    <ContactsTable />
                </Deferred>
            </div>
        </>
    );
}

function ContactsTable() {
    const { contacts, list, can, workspace } = usePage<ContactsIndexPageProps>().props;
    const { setFilter } = useListFilters(list);

    // P2-002 (code review) — aliniat la `Pages/Accounts/Index.tsx`: o listă goală
    // DIN CAUZA unui filtru nu e aceeași stare ca o listă goală de-adevăratelea, iar
    // acțiunea oferită diferă («Clear filters» vs. «Create your first contact»).
    const hasFilters = Object.keys(list.filter).length > 0;

    if (contacts.data.length === 0) {
        return (
            <EmptyState
                message={hasFilters ? 'No contacts match this filter.' : 'No contacts yet.'}
                action={
                    hasFilters ? (
                        <Button onClick={() => clearFilters(setFilter)}>Clear filters</Button>
                    ) : (
                        can.create &&
                        workspace && <ButtonLink variant="primary" href={`/${workspace.slug}/contacts/create`}>Create your first contact</ButtonLink>
                    )
                }
            />
        );
    }

    return (
        <div className="flex flex-col gap-3">
            <table className="w-full border-separate border-spacing-0 overflow-hidden rounded-lg border border-border bg-surface text-sm">
                <caption className="sr-only">Contacts</caption>
                <thead>
                    <tr className="text-left text-text-2">
                        <th scope="col" className="border-b border-border-soft px-4 py-2 font-medium">
                            Name
                        </th>
                        <th scope="col" className="border-b border-border-soft px-4 py-2 font-medium">
                            Account
                        </th>
                        <th scope="col" className="border-b border-border-soft px-4 py-2 font-medium">
                            Email
                        </th>
                        <th scope="col" className="border-b border-border-soft px-4 py-2 font-medium">
                            Title
                        </th>
                        <th scope="col" className="border-b border-border-soft px-4 py-2 font-medium">
                            <span className="sr-only">Actions</span>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    {contacts.data.map((contact) => (
                        <tr key={contact.id} className="hover:bg-row-hover">
                            <td className="border-b border-border-soft px-4 py-2">
                                <Link
                                    href={workspace ? `/${workspace.slug}/contacts/${contact.id}` : '#'}
                                    className="font-medium text-text underline-offset-2 hover:underline"
                                >
                                    {contact.fullName}
                                </Link>
                                {contact.isPrimary && (
                                    <StatusBadge tone="accent">
                                        <span className="ml-1">Primary</span>
                                    </StatusBadge>
                                )}
                            </td>
                            <td className="border-b border-border-soft px-4 py-2 text-text-2">
                                {contact.account && workspace ? (
                                    <Link
                                        href={`/${workspace.slug}/accounts/${contact.account.id}`}
                                        className="underline-offset-2 hover:underline"
                                    >
                                        {contact.account.name}
                                    </Link>
                                ) : (
                                    <span className="text-text-3">No account</span>
                                )}
                            </td>
                            <td className="border-b border-border-soft px-4 py-2 text-text-2">{contact.email ?? '—'}</td>
                            <td className="border-b border-border-soft px-4 py-2 text-text-2">{contact.title ?? '—'}</td>
                            <td className="border-b border-border-soft px-4 py-2 text-right">
                                {contact.can.edit && workspace && (
                                    <Link
                                        href={`/${workspace.slug}/contacts/${contact.id}/edit`}
                                        className="text-accent-text underline-offset-2 hover:underline"
                                    >
                                        Edit<span className="sr-only"> {contact.fullName}</span>
                                    </Link>
                                )}
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
            <CursorPagination nextCursor={contacts.nextCursor} prevCursor={contacts.prevCursor} />
        </div>
    );
}

function clearFilters(setFilter: (key: string, value: string | null) => void): void {
    ['q', 'account'].forEach((key) => setFilter(key, null));
}

function exportHref(currentUrl: string, exportPath: string): string {
    const query = currentUrl.split('?')[1];

    return query ? `${exportPath}?${query}` : exportPath;
}

ContactsIndex.layout = (page: ReactNode) => <AppLayout>{page}</AppLayout>;
