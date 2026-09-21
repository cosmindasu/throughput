import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import Button, { ButtonLink } from '@/Components/Button';
import ConfirmDialog from '@/Components/ConfirmDialog';
import HistoryTab from '@/Components/History/HistoryTab';
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
    const { t } = useTranslation('contacts');
    const { contact, can, workspace } = usePage<ContactsShowPageProps>().props;
    const [confirmingDelete, setConfirmingDelete] = useState(false);
    const [deleting, setDeleting] = useState(false);

    const destroy = () => {
        if (!workspace) {
            return;
        }

        setDeleting(true);
        router.delete(`/${workspace.slug}/contacts/${contact.id}`, {
            onFinish: () => {
                setDeleting(false);
                setConfirmingDelete(false);
            },
        });
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
                                <ButtonLink href={`/${workspace.slug}/contacts/${contact.id}/edit`}>{t('show.actions.edit')}</ButtonLink>
                            )}
                            {can.delete && (
                                <Button variant="danger" onClick={() => setConfirmingDelete(true)}>
                                    {t('show.actions.delete')}
                                </Button>
                            )}
                        </>
                    }
                />

                {contact.isPrimary && <StatusBadge tone="accent">{t('show.primaryContactBadge')}</StatusBadge>}

                <div className="grid gap-4 sm:grid-cols-2">
                    <dl className="flex flex-col gap-3 rounded-lg border border-border bg-surface p-4 text-sm">
                        <div>
                            <dt className="text-text-2">{t('show.details.email')}</dt>
                            <dd className="text-text">{contact.email ?? '—'}</dd>
                        </div>
                        <div>
                            <dt className="text-text-2">{t('show.details.phone')}</dt>
                            <dd className="text-text">{contact.phone ?? '—'}</dd>
                        </div>
                        <div>
                            <dt className="text-text-2">{t('show.details.account')}</dt>
                            <dd className="text-text">
                                {contact.account && workspace ? (
                                    <Link
                                        href={`/${workspace.slug}/accounts/${contact.account.id}`}
                                        className="underline-offset-2 hover:underline"
                                    >
                                        {contact.account.name}
                                    </Link>
                                ) : (
                                    t('show.details.accountNone')
                                )}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-text-2">{t('show.details.marketing')}</dt>
                            <dd className="text-text">
                                {contact.optOut ? t('show.details.marketingOptedOut') : t('show.details.marketingSubscribed')}
                            </dd>
                        </div>
                    </dl>

                    <section aria-label={t('show.dealsSection.heading')} className="rounded-lg border border-border bg-surface p-4 text-sm">
                        <h2 className="text-sm font-medium text-text-2">{t('show.dealsSection.heading')}</h2>
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
                                        {/* `deal.status` (open/won/lost) e domeniul „deals", nu al meu
                                            (`contacts`) — las raw, vezi raportul lotului (namespace-ul
                                            `deals` e gol, nimic de refolosit încă). */}
                                        <StatusBadge tone={dealStatusTone[deal.status as keyof typeof dealStatusTone] ?? 'neutral'}>
                                            {deal.status}
                                        </StatusBadge>
                                    </li>
                                ))}
                            </ul>
                        ) : (
                            <p className="mt-3 text-text-2">{t('show.dealsSection.empty')}</p>
                        )}
                    </section>
                </div>

                {/* FR-AUD-02, §17.3 */}
                <section aria-label={t('show.historySection')} className="flex flex-col gap-3">
                    <h2 className="text-sm font-medium text-text">{t('show.historySection')}</h2>
                    <HistoryTab entityType="contact" entityId={contact.id} />
                </section>

                <ConfirmDialog
                    open={confirmingDelete}
                    title={t('show.deleteDialog.title')}
                    onClose={() => setConfirmingDelete(false)}
                    onConfirm={destroy}
                    confirmLabel={t('show.deleteDialog.confirm')}
                    confirmVariant="danger"
                    processing={deleting}
                >
                    {t('show.deleteDialog.body', { name: contact.fullName })}
                </ConfirmDialog>
            </div>
        </>
    );
}

ContactsShow.layout = (page: ReactNode) => <AppLayout>{page}</AppLayout>;
