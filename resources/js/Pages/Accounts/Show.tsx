import { Deferred, Head, router, usePage } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';
import Button, { ButtonLink } from '@/Components/Button';
import ConfirmDialog from '@/Components/ConfirmDialog';
import EmptyState from '@/Components/EmptyState';
import HistoryTab from '@/Components/History/HistoryTab';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import AppLayout from '@/Layouts/AppLayout';
import { formatMoney } from '@/lib/money';
import type { AccountsShowPageProps } from '@/types/generated';

/**
 * Accounts/Show — FR-CRM-04. Tab-ul „Activity" e deferred (`activity`); contactele și
 * deals-urile sunt liste scurte per cont, nu au nevoie de paginare pe cursor.
 */
export default function Show() {
    const { account, contacts, deals, activity, deletionBlockedReason, can, workspace } = usePage<AccountsShowPageProps>().props;
    const base = workspace ? `/${workspace.slug}` : '';
    const [confirmingDelete, setConfirmingDelete] = useState(false);
    const [deleting, setDeleting] = useState(false);

    const destroy = () => {
        setDeleting(true);
        router.delete(`${base}/accounts/${account.id}`, {
            onFinish: () => {
                setDeleting(false);
                setConfirmingDelete(false);
            },
        });
    };

    return (
        <>
            <Head title={account.name} />

            <div className="flex flex-col gap-6">
                <PageHeader
                    title={account.name}
                    description={
                        <span className="flex items-center gap-2">
                            <StatusBadge tone={account.status === 'active' ? 'success' : 'neutral'}>{account.status}</StatusBadge>
                            {account.industry && <span>{account.industry}</span>}
                        </span>
                    }
                    actions={
                        <>
                            {can.createContact && (
                                <ButtonLink href={`${base}/contacts/create?account=${account.id}`}>Add contact</ButtonLink>
                            )}
                            {can.createDeal && <ButtonLink href={`${base}/deals/create?account=${account.id}`}>New deal</ButtonLink>}
                            {can.edit && <ButtonLink href={`${base}/accounts/${account.id}/edit`}>Edit</ButtonLink>}
                            {can.delete && (
                                <Button variant="danger" onClick={() => setConfirmingDelete(true)}>
                                    Delete
                                </Button>
                            )}
                        </>
                    }
                />

                <dl className="grid grid-cols-1 gap-4 rounded-lg border border-border bg-surface p-4 sm:grid-cols-3">
                    <Detail label="Domain" value={account.domain} />
                    <Detail label="Phone" value={account.phone} />
                    <Detail label="Credit terms" value={account.creditTerms} />
                    <Detail label="Owner" value={account.owner?.name ?? 'Unassigned'} />
                    <Detail label="Source" value={account.source} />
                    <Detail label="Tags" value={account.tags.length > 0 ? account.tags.join(', ') : null} />
                </dl>

                <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
                    <section aria-label="Contacts" className="flex flex-col gap-3">
                        <h2 className="text-sm font-medium text-text">Contacts</h2>
                        {contacts.length === 0 ? (
                            <EmptyState message="No contacts yet." />
                        ) : (
                            <ul className="flex flex-col divide-y divide-border-soft rounded-lg border border-border bg-surface">
                                {contacts.map((contact) => (
                                    <li key={contact.id} className="flex items-center justify-between px-4 py-2.5">
                                        <div>
                                            <a href={`${base}/contacts/${contact.id}`} className="font-medium text-accent-text hover:underline">
                                                {contact.name}
                                            </a>
                                            {contact.isPrimary && (
                                                <span className="ml-2">
                                                    <StatusBadge tone="accent">Primary</StatusBadge>
                                                </span>
                                            )}
                                            <div className="text-xs text-text-3">{contact.title}</div>
                                        </div>
                                        <div className="text-right text-xs text-text-2">
                                            {contact.email && <div>{contact.email}</div>}
                                            {contact.phone && <div>{contact.phone}</div>}
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>

                    <section aria-label="Deals" className="flex flex-col gap-3">
                        <h2 className="text-sm font-medium text-text">Deals</h2>
                        {deals.length === 0 ? (
                            <EmptyState message="No deals yet." />
                        ) : (
                            <ul className="flex flex-col divide-y divide-border-soft rounded-lg border border-border bg-surface">
                                {deals.map((deal) => (
                                    <li key={deal.id} className="flex items-center justify-between px-4 py-2.5">
                                        <a href={deal.url} className="font-medium text-accent-text hover:underline">
                                            {deal.title}
                                        </a>
                                        <div className="text-right text-xs text-text-2">
                                            {/* Același format ca Deals/Index și kanbanul (`formatMoney`, două
                                                zecimale fixe), pe cifre mono — nu `USD 12,345.5` pe sans. */}
                                            <div className="numeric">{formatMoney(deal.value, deal.currency)}</div>
                                            <div>{deal.stageName ?? deal.status}</div>
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>
                </div>

                <section aria-label="Activity" className="flex flex-col gap-3">
                    <h2 className="text-sm font-medium text-text">Activity</h2>
                    <Deferred data="activity" fallback={<EmptyState message="Loading activity…" />}>
                        {activity && activity.length > 0 ? (
                            <ol className="flex flex-col divide-y divide-border-soft rounded-lg border border-border bg-surface">
                                {activity.map((entry) => (
                                    <li key={entry.id} className="flex items-center justify-between px-4 py-2.5 text-sm">
                                        {entry.url ? (
                                            <a href={entry.url} className="text-accent-text hover:underline">
                                                {entry.description}
                                            </a>
                                        ) : (
                                            <span className="text-text">{entry.description}</span>
                                        )}
                                        <span className="text-xs text-text-3">
                                            {entry.at ? new Date(entry.at).toLocaleString('en-US') : ''}
                                        </span>
                                    </li>
                                ))}
                            </ol>
                        ) : (
                            activity && <EmptyState message="No activity recorded yet." />
                        )}
                    </Deferred>
                </section>

                {/* FR-AUD-02, §17.3 — distinct de „Activity" de mai sus (cronologie amestecată
                    de business), „History" e strict `activity_log`: autor/dată/valoare veche/nouă. */}
                <section aria-label="History" className="flex flex-col gap-3">
                    <h2 className="text-sm font-medium text-text">History</h2>
                    <HistoryTab entityType="account" entityId={account.id} />
                </section>
            </div>

            <ConfirmDialog
                open={confirmingDelete}
                title={deletionBlockedReason ? 'Cannot delete this account' : `Delete ${account.name}?`}
                onConfirm={deletionBlockedReason ? undefined : destroy}
                confirmVariant="danger"
                confirmLabel="Delete"
                processing={deleting}
                onClose={() => setConfirmingDelete(false)}
            >
                {deletionBlockedReason ?? 'This action cannot be undone.'}
            </ConfirmDialog>
        </>
    );
}

function Detail({ label, value }: { label: string; value: string | null | undefined }) {
    return (
        <div>
            <dt className="text-xs text-text-3">{label}</dt>
            <dd className="text-sm text-text">{value || '—'}</dd>
        </div>
    );
}

Show.layout = (page: ReactNode) => <AppLayout>{page}</AppLayout>;
