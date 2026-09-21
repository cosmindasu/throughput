import { Head, router, usePage } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import Button from '@/Components/Button';
import EmptyState from '@/Components/EmptyState';
import Field, { controlClass } from '@/Components/Form/Field';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import AppLayout from '@/Layouts/AppLayout';
import type { UnassignedIndexPageProps } from '@/types/generated';

/**
 * FR-TEN-05 — vederea „Unassigned" (§6.4.1, ADR-011): deals deschise + comenzi active ai
 * membrilor dezactivați, grupate pe tip. NU conturi (decizie deja luată, vezi raportul
 * pachetului „Membri și roluri").
 *
 * Reatribuirea de aici e pe ÎNTREAGA vedere („owner=unassigned" + statusul potrivit), nu o
 * selecție rând cu rând: vederea ÎNSĂȘI e deja setul care are nevoie de un owner nou.
 */
export default function UnassignedIndex() {
    const { deals, orders, can, activeMembers } = usePage<UnassignedIndexPageProps>().props;
    const { t } = useTranslation('dashboard');
    const [newOwnerUserId, setNewOwnerUserId] = useState('');
    const [processing, setProcessing] = useState(false);
    // Audit de accesibilitate (P1, pct. 1) — la fel ca `Settings/Members/Index.tsx`:
    // `page.props.errors` din cererea curentă, legate de câmp (`new_owner_user_id`) sau
    // afișate ca alertă generică (`selection` — plafonul DEMO_MODE, `DispatchBulkOperationAction`).
    const [errors, setErrors] = useState<Record<string, string>>({});

    const isEmpty = deals.data.length === 0 && orders.data.length === 0;
    const generalError = errors.selection;

    const reassign = () => {
        if (newOwnerUserId === '' || processing) {
            return;
        }

        setProcessing(true);
        setErrors({});

        router.post(
            '/unassigned/reassign',
            { new_owner_user_id: newOwnerUserId },
            {
                // Succesul redirecționează spre `/bulk/groups/{id}` (altă pagină) — nu mai
                // e nimic de resetat local aici la succes, componenta se demontează.
                onError: (pageErrors) => {
                    setProcessing(false);
                    setErrors(pageErrors);
                },
            },
        );
    };

    return (
        <>
            {/* SC 2.4.2 (Page Titled) — fără `<Head>`, titlul documentului rămâne cel al
                paginii ANTERIOARE într-un SPA Inertia: cine navighează cu un cititor de
                ecran aude titlul vechi la fiecare intrare aici. */}
            <Head title={t('dashboard:unassigned.title')} />

            <PageHeader title={t('dashboard:unassigned.title')} description={t('dashboard:unassigned.description')} />

            {!isEmpty && can.reassign && (
                <div className="mt-4 flex flex-col gap-3 rounded-lg border border-border bg-surface p-4">
                    {generalError && (
                        <p role="alert" className="rounded-md bg-danger-tint px-3 py-2 text-sm text-danger">
                            {generalError}
                        </p>
                    )}
                    <div className="flex flex-wrap items-end gap-3">
                        <div className="min-w-[16rem]">
                            <Field label={t('dashboard:unassigned.reassignLabel')} error={errors.new_owner_user_id}>
                                {(control) => (
                                    <select
                                        {...control}
                                        className={controlClass}
                                        value={newOwnerUserId}
                                        onChange={(event) => setNewOwnerUserId(event.target.value)}
                                    >
                                        <option value="">{t('dashboard:unassigned.chooseMember')}</option>
                                        {activeMembers.map((member) => (
                                            <option key={member.id} value={member.id}>
                                                {member.name}
                                            </option>
                                        ))}
                                    </select>
                                )}
                            </Field>
                        </div>
                        <Button
                            variant="primary"
                            disabled={newOwnerUserId === ''}
                            aria-disabled={processing || newOwnerUserId === '' ? true : undefined}
                            onClick={reassign}
                        >
                            {processing ? t('dashboard:unassigned.reassigning') : t('dashboard:unassigned.reassignAll')}
                        </Button>
                    </div>
                </div>
            )}

            {isEmpty && (
                <div className="mt-6">
                    <EmptyState message={t('dashboard:unassigned.empty')} />
                </div>
            )}

            {deals.data.length > 0 && (
                <section className="mt-6">
                    <h2 id="unassigned-deals-heading" className="text-sm font-semibold text-text">
                        {t('dashboard:unassigned.openDealsHeading', { count: deals.data.length })}
                    </h2>
                    <div className="mt-2 overflow-x-auto rounded-lg border border-border">
                        <table className="w-full text-left text-sm" aria-labelledby="unassigned-deals-heading">
                            <thead className="border-b border-border bg-surface text-text-2">
                                <tr>
                                    <th scope="col" className="px-4 py-2 font-medium">{t('dashboard:unassigned.columns.dealTitle')}</th>
                                    <th scope="col" className="px-4 py-2 font-medium">{t('dashboard:unassigned.columns.account')}</th>
                                    <th scope="col" className="px-4 py-2 font-medium">{t('dashboard:unassigned.columns.stage')}</th>
                                    <th scope="col" className="px-4 py-2 font-medium">{t('dashboard:unassigned.columns.owner')}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {deals.data.map((deal) => (
                                    <tr key={deal.id} className="border-b border-border-soft last:border-0">
                                        <td className="px-4 py-3 text-text">{deal.title}</td>
                                        <td className="px-4 py-3 text-text-2">{deal.account.name}</td>
                                        <td className="px-4 py-3 text-text-2">{deal.stage.name}</td>
                                        <td className="px-4 py-3 text-text-2">{deal.owner.name}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </section>
            )}

            {orders.data.length > 0 && (
                <section className="mt-6">
                    <h2 id="unassigned-orders-heading" className="text-sm font-semibold text-text">
                        {t('dashboard:unassigned.activeOrdersHeading', { count: orders.data.length })}
                    </h2>
                    <div className="mt-2 overflow-x-auto rounded-lg border border-border">
                        <table className="w-full text-left text-sm" aria-labelledby="unassigned-orders-heading">
                            <thead className="border-b border-border bg-surface text-text-2">
                                <tr>
                                    <th scope="col" className="px-4 py-2 font-medium">{t('dashboard:unassigned.columns.order')}</th>
                                    <th scope="col" className="px-4 py-2 font-medium">{t('dashboard:unassigned.columns.account')}</th>
                                    <th scope="col" className="px-4 py-2 font-medium">{t('dashboard:unassigned.columns.status')}</th>
                                    <th scope="col" className="px-4 py-2 font-medium">{t('dashboard:unassigned.columns.owner')}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {orders.data.map((order) => (
                                    <tr key={order.id} className="border-b border-border-soft last:border-0">
                                        <td className="px-4 py-3 text-text">{order.orderNumber ?? `#${order.id.slice(-8)}`}</td>
                                        <td className="px-4 py-3 text-text-2">{order.account.name}</td>
                                        <td className="px-4 py-3">
                                            <StatusBadge tone="accent">{order.statusLabel}</StatusBadge>
                                        </td>
                                        <td className="px-4 py-3 text-text-2">{order.owner.name}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </section>
            )}
        </>
    );
}

UnassignedIndex.layout = (page: ReactNode) => <AppLayout>{page}</AppLayout>;
