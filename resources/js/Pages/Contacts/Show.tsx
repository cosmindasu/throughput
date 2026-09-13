import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';
import Button, { ButtonLink } from '@/Components/Button';
import ConfirmDialog from '@/Components/ConfirmDialog';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import AppLayout from '@/Layouts/AppLayout';
import type { ContactsShowPageProps } from '@/types/generated';

const dealStatusTone = { open: 'accent', won: 'success', lost: 'danger' } as const;

/**
 * Detaliul unui contact — FR-CRM-04-adiacent (nu are propriul tab „Activity", ăla e
 * al conturilor): date de contact, contul (link), deal-urile unde e contact
 * principal (`Contact::deals()`, `primary_contact_id`).
 */
export default function ContactsShow() {
    const { contact, can, workspace } = usePage<ContactsShowPageProps>().props;
    const [confirmingDelete, setConfirmingDelete] = useState(false);

    const destroy = () => {
        if (!workspace) {
            return;
        }

        router.delete(`/${workspace.slug}/contacts/${contact.id}`);
    };

    return (
        <>
            <Head title={contact.fullName} />

            <div className="flex flex-col gap-6">
                <PageHeader
                    title={contact.fullName}
                    description={contact.title ?? undefined}
                    actions={
                        <>
                            {can.edit && workspace && (
                                <ButtonLink href={`/${workspace.slug}/contacts/${contact.id}/edit`}>Edit</ButtonLink>
                            )}
                            {can.delete && (
                                <Button variant="danger" onClick={() => setConfirmingDelete(true)}>
                                    Delete
                                </Button>
                            )}
                        </>
                    }
                />

                {contact.isPrimary && <StatusBadge tone="accent">Primary contact</StatusBadge>}

                <div className="grid gap-4 sm:grid-cols-2">
                    <dl className="flex flex-col gap-3 rounded-lg border border-border bg-surface p-4 text-sm">
                        <div>
                            <dt className="text-text-2">Email</dt>
                            <dd className="text-text">{contact.email ?? '—'}</dd>
                        </div>
                        <div>
                            <dt className="text-text-2">Phone</dt>
                            <dd className="text-text">{contact.phone ?? '—'}</dd>
                        </div>
                        <div>
                            <dt className="text-text-2">Account</dt>
                            <dd className="text-text">
                                {contact.account && workspace ? (
                                    <Link
                                        href={`/${workspace.slug}/accounts/${contact.account.id}`}
                                        className="underline-offset-2 hover:underline"
                                    >
                                        {contact.account.name}
                                    </Link>
                                ) : (
                                    'No account yet'
                                )}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-text-2">Marketing</dt>
                            <dd className="text-text">{contact.optOut ? 'Opted out' : 'Subscribed'}</dd>
                        </div>
                    </dl>

                    <section aria-label="Deals" className="rounded-lg border border-border bg-surface p-4 text-sm">
                        <h2 className="text-sm font-medium text-text-2">Deals as primary contact</h2>
                        {contact.deals && contact.deals.length > 0 ? (
                            <ul className="mt-3 flex flex-col gap-2">
                                {contact.deals.map((deal) => (
                                    <li key={deal.id} className="flex items-center justify-between gap-2">
                                        <Link
                                            href={workspace ? `/${workspace.slug}/deals/${deal.id}` : '#'}
                                            className="text-text underline-offset-2 hover:underline"
                                        >
                                            {deal.title}
                                        </Link>
                                        <StatusBadge tone={dealStatusTone[deal.status as keyof typeof dealStatusTone] ?? 'neutral'}>
                                            {deal.status}
                                        </StatusBadge>
                                    </li>
                                ))}
                            </ul>
                        ) : (
                            <p className="mt-3 text-text-2">No deals list this contact as primary yet.</p>
                        )}
                    </section>
                </div>

                <ConfirmDialog
                    open={confirmingDelete}
                    title="Delete this contact?"
                    onClose={() => setConfirmingDelete(false)}
                    onConfirm={destroy}
                    confirmLabel="Delete"
                    confirmVariant="danger"
                >
                    This can’t be undone. Deals and orders that reference {contact.fullName} keep their history but lose the link.
                </ConfirmDialog>
            </div>
        </>
    );
}

ContactsShow.layout = (page: ReactNode) => <AppLayout>{page}</AppLayout>;
