import { Deferred, Head, router, usePage } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import Button, { ButtonLink } from '@/Components/Button';
import ConfirmDialog from '@/Components/ConfirmDialog';
import EmptyState from '@/Components/EmptyState';
import HistoryTab from '@/Components/History/HistoryTab';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import TableSkeleton from '@/Components/TableSkeleton';
import AppLayout from '@/Layouts/AppLayout';
import { useLocale } from '@/hooks/useLocale';
import { getDateTimeFormat } from '@/lib/format';
import { formatMoney } from '@/lib/money';
import type { AccountsShowPageProps } from '@/types/generated';

/**
 * Echivalentul EXACT al `toLocaleString('en-US')` FĂRĂ opțiuni pe un obiect `Date`
 * (verificat: `3/14/2026, 7:09:32 PM`, nu forma medie cu lună scrisă) — Val 3, FR-I18N-03.
 * Cronologia „Activity" arăta timpul cu secunde, spre deosebire de `formatDateTime` (fără
 * secunde, lună scrisă) folosit în restul aplicației — formă distinctă, păstrată ca atare.
 */
const ACCOUNT_ACTIVITY_TIMESTAMP_OPTIONS: Intl.DateTimeFormatOptions = {
    year: 'numeric',
    month: 'numeric',
    day: 'numeric',
    hour: 'numeric',
    minute: 'numeric',
    second: 'numeric',
};

/**
 * Accounts/Show — FR-CRM-04. Tab-ul „Activity" e deferred (`activity`); contactele și
 * deals-urile sunt liste scurte per cont, nu au nevoie de paginare pe cursor.
 */
export default function Show() {
    const { t } = useTranslation('accounts');
    const locale = useLocale();
    const { account, contacts, deals, activity, deletionBlockedReason, can, workspace } = usePage<AccountsShowPageProps>().props;
    const base = workspace ? `/${workspace.slug}` : '';
    const [confirmingDelete, setConfirmingDelete] = useState(false);
    const [deleting, setDeleting] = useState(false);
    // FE-01 (audit) — dialogul se închide DOAR la succes; la eroare rămâne deschis, cu
    // mesajul afișat în `role="alert"` chiar în el (`.ai/rules/frontend.md`, „Dialogul
    // închis și la eroare”).
    const [deleteError, setDeleteError] = useState<string | null>(null);

    const destroy = () => {
        setDeleting(true);
        setDeleteError(null);
        router.delete(`${base}/accounts/${account.id}`, {
            onSuccess: () => setConfirmingDelete(false),
            onError: (errors) => setDeleteError(Object.values(errors)[0] ?? t('show.deleteDialog.error')),
            onFinish: () => setDeleting(false),
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
                            <StatusBadge tone={account.status === 'active' ? 'success' : 'neutral'}>{t(`status.${account.status}`)}</StatusBadge>
                            {account.industry && <span>{account.industry}</span>}
                        </span>
                    }
                    actions={
                        <>
                            {can.createContact && (
                                <ButtonLink href={`${base}/contacts/create?account=${account.id}`}>{t('show.actions.addContact')}</ButtonLink>
                            )}
                            {can.createDeal && <ButtonLink href={`${base}/deals/create?account=${account.id}`}>{t('show.actions.newDeal')}</ButtonLink>}
                            {can.edit && <ButtonLink href={`${base}/accounts/${account.id}/edit`}>{t('show.actions.edit')}</ButtonLink>}
                            {can.delete && (
                                <Button variant="danger" onClick={() => setConfirmingDelete(true)}>
                                    {t('show.actions.delete')}
                                </Button>
                            )}
                        </>
                    }
                />

                <dl className="grid grid-cols-1 gap-4 rounded-lg border border-border bg-surface p-4 sm:grid-cols-3">
                    <Detail label={t('show.details.domain')} value={account.domain} />
                    <Detail label={t('show.details.phone')} value={account.phone} />
                    {/* `account.creditTerms` rămâne RAW (`net_30`, nu „Net 30") — enum afișat
                        direct, fără componentă de etichetă, la fel ca înainte de Val 3 (vezi
                        raportul lotului). */}
                    <Detail label={t('show.details.creditTerms')} value={account.creditTerms} />
                    <Detail label={t('show.details.owner')} value={account.owner?.name ?? t('show.details.ownerUnassigned')} />
                    <Detail label={t('show.details.source')} value={account.source} />
                    <Detail label={t('show.details.tags')} value={account.tags.length > 0 ? account.tags.join(', ') : null} />
                </dl>

                <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
                    <section aria-label={t('show.sections.contacts')} className="flex flex-col gap-3">
                        <h2 className="text-sm font-medium text-text">{t('show.sections.contacts')}</h2>
                        {contacts.length === 0 ? (
                            <EmptyState message={t('show.empty.contacts')} />
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
                                                    <StatusBadge tone="accent">{t('show.primaryContactBadge')}</StatusBadge>
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

                    <section aria-label={t('show.sections.deals')} className="flex flex-col gap-3">
                        <h2 className="text-sm font-medium text-text">{t('show.sections.deals')}</h2>
                        {deals.length === 0 ? (
                            <EmptyState message={t('show.empty.deals')} />
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
                                            <div className="numeric">{formatMoney(deal.value, deal.currency, locale)}</div>
                                            {/* `deal.stageName ?? deal.status` — `deal.status` (open/won/lost) e
                                                domeniul „deals", nu al meu (`accounts`); las raw, vezi raportul
                                                lotului (namespace-ul `deals` e gol, nimic de refolosit încă). */}
                                            <div>{deal.stageName ?? deal.status}</div>
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>
                </div>

                <section aria-label={t('show.sections.activity')} className="flex flex-col gap-3">
                    <h2 className="text-sm font-medium text-text">{t('show.sections.activity')}</h2>
                    <Deferred data="activity" fallback={<TableSkeleton rows={3} columns={2} />}>
                        {activity && activity.length > 0 ? (
                            <ol className="flex flex-col divide-y divide-border-soft rounded-lg border border-border bg-surface">
                                {activity.map((entry) => (
                                    <li key={entry.id} className="flex items-center justify-between px-4 py-2.5 text-sm">
                                        {/* `entry.description` vine GATA CONSTRUIT din
                                            `App\Support\Accounts\AccountActivityTimeline` (interpolează
                                            titluri de deal/nume de etapă — conținut de utilizator amestecat cu
                                            text — plus `Str::headline()` pentru rândurile din `activity_log`,
                                            fără trecere prin catalogul de traduceri). Backend, în afara celor
                                            14 fișiere ale lotului — nu-l reconstrui aici (vezi raportul). */}
                                        {entry.url ? (
                                            <a href={entry.url} className="text-accent-text hover:underline">
                                                {entry.description}
                                            </a>
                                        ) : (
                                            <span className="text-text">{entry.description}</span>
                                        )}
                                        <span className="text-xs text-text-3">
                                            {entry.at ? getDateTimeFormat(locale, ACCOUNT_ACTIVITY_TIMESTAMP_OPTIONS).format(new Date(entry.at)) : ''}
                                        </span>
                                    </li>
                                ))}
                            </ol>
                        ) : (
                            activity && <EmptyState message={t('show.empty.activityNone')} />
                        )}
                    </Deferred>
                </section>

                {/* FR-AUD-02, §17.3 — distinct de „Activity" de mai sus (cronologie amestecată
                    de business), „History" e strict `activity_log`: autor/dată/valoare veche/nouă. */}
                <section aria-label={t('show.sections.history')} className="flex flex-col gap-3">
                    <h2 className="text-sm font-medium text-text">{t('show.sections.history')}</h2>
                    <HistoryTab entityType="account" entityId={account.id} />
                </section>
            </div>

            <ConfirmDialog
                open={confirmingDelete}
                title={deletionBlockedReason ? t('show.deleteDialog.blockedTitle') : t('show.deleteDialog.title', { name: account.name })}
                onConfirm={deletionBlockedReason ? undefined : destroy}
                confirmVariant="danger"
                confirmLabel={t('show.deleteDialog.confirm')}
                processing={deleting}
                onClose={() => {
                    setConfirmingDelete(false);
                    setDeleteError(null);
                }}
            >
                {deleteError && (
                    <p role="alert" className="mb-2 rounded-md bg-danger-tint px-2 py-1.5 text-danger">
                        {deleteError}
                    </p>
                )}
                {/* `deletionBlockedReason` vine din server ca text OPAC (motivul exact al
                    blocajului) — nu trece prin `t()`, la fel ca `entry.description` de mai sus. */}
                {deletionBlockedReason ?? t('show.deleteDialog.body')}
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
