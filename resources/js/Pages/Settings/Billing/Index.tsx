import { Head, router, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import Button from '@/Components/Button';
import PageHeader from '@/Components/PageHeader';
import StatusBadge, { type BadgeTone } from '@/Components/StatusBadge';
import AppLayout from '@/Layouts/AppLayout';
import type { BillingIndexPageProps } from '@/types/generated';

const STATUS_TONE: Record<string, BadgeTone> = {
    active: 'success',
    past_due: 'warning',
    unpaid: 'danger',
    canceled: 'danger',
};

function statusLabel(status: string | null): string {
    if (status === null) {
        return 'No subscription yet';
    }

    return status.replace(/_/g, ' ');
}

/**
 * `Settings/Billing/Index` — FR-BILL-01, Owner-only (§7.4, §7.3). Managerul nu ajunge
 * niciodată aici: cardul din `Settings/Index.tsx` e absent pentru el (`can.billing`,
 * `App\Http\Controllers\Web\Settings\SettingsController`), iar o vizită directă a URL-ului
 * primește 403 din `BillingController::authorizeBillingView()`.
 *
 * `accessLevel === 'blocked'` (`canceled`) — ecran UNIC (US-BILL-04, Gherkin): restul
 * paginii normale nu se randează deloc, doar mesajul + „Reactivate". Server-side,
 * `EnsureSubscriptionAccess` a redirectat deja aici orice altă rută din workspace — asta
 * confirmă vizual „nimic în afara paginii de billing/reactivare".
 */
export default function BillingIndex() {
    const { subscription, invoices, can, workspace } = usePage<BillingIndexPageProps>().props;

    // Cale absolută cu slug-ul workspace-ului curent — fără Ziggy în proiect (vezi
    // `AppLayout.tsx`), iar un URL relativ (`'portal'`) s-ar rezolva greșit față de calea
    // curentă a browserului (elimină ultimul segment, `billing`, nu îl extinde).
    const openPortal = () => {
        if (!workspace) {
            return;
        }

        router.post(`/${workspace.slug}/settings/billing/portal`, {}, { preserveScroll: true });
    };

    if (subscription.accessLevel === 'blocked') {
        return (
            <>
                <Head title="Subscription canceled" />

                <div className="flex flex-col items-start gap-4 rounded-lg border border-border bg-surface p-6">
                    <StatusBadge tone="danger">Subscription canceled</StatusBadge>
                    <p className="text-sm text-text-2">
                        This workspace&apos;s Throughput subscription was canceled. Your data is kept for 30 days
                        from cancellation and this workspace can be reactivated at any time during that window,
                        with no re-onboarding.
                    </p>
                    {subscription.canceledAt && (
                        <p className="text-xs text-text-2">
                            Canceled on {new Date(subscription.canceledAt).toLocaleDateString()}.
                        </p>
                    )}
                    {can.manage && (
                        <Button variant="primary" onClick={openPortal}>
                            Reactivate
                        </Button>
                    )}
                </div>
            </>
        );
    }

    return (
        <>
            <Head title="Billing & Subscription" />

            <div className="flex flex-col gap-6">
                <PageHeader title="Billing & Subscription" />

                <div className="flex flex-col gap-4 rounded-lg border border-border bg-surface p-4">
                    <div className="flex items-center justify-between gap-4">
                        <div>
                            <h2 className="text-sm font-medium text-text">Current plan</h2>
                            <p className="mt-1 text-sm text-text-2">
                                {subscription.plan ?? 'No active Stripe subscription for this workspace.'}
                            </p>
                        </div>
                        <StatusBadge tone={STATUS_TONE[subscription.status ?? ''] ?? 'neutral'}>
                            {statusLabel(subscription.status)}
                        </StatusBadge>
                    </div>

                    <div>
                        <h2 className="text-sm font-medium text-text">Payment method</h2>
                        <p className="mt-1 text-sm text-text-2">
                            {subscription.paymentMethod
                                ? `${subscription.paymentMethod.type} ending in ${subscription.paymentMethod.lastFour ?? '????'}`
                                : 'No payment method on file yet.'}
                        </p>
                    </div>

                    {can.manage && (
                        <div>
                            <Button variant="secondary" onClick={openPortal}>
                                Manage billing
                            </Button>
                        </div>
                    )}
                </div>

                <div className="flex flex-col gap-3 rounded-lg border border-border bg-surface p-4">
                    <h2 id="invoice-history-heading" className="text-sm font-medium text-text">
                        Invoice history
                    </h2>

                    {invoices.length === 0 ? (
                        <p className="text-sm text-text-2">No invoices yet.</p>
                    ) : (
                        /* `aria-labelledby` către headingul vizibil dedicat de deasupra, nu un
                           `<caption>` separat — excepția din `.ai/rules/frontend.md`. Tabelul
                           n-avea nici nume, nici `scope` pe celulele de antet: singurul din
                           aplicație cu `<th>` fără `scope="col"` (SC 1.3.1 — relația
                           antet/celulă e chiar ce cere criteriul). */
                        <table className="w-full text-left text-sm" aria-labelledby="invoice-history-heading">
                            <thead>
                                <tr className="border-b border-border-soft text-text-2">
                                    <th scope="col" className="py-2 pr-4 font-medium">Date</th>
                                    <th scope="col" className="py-2 pr-4 font-medium">Total</th>
                                    <th scope="col" className="py-2 pr-4 font-medium">Status</th>
                                    <th scope="col" className="py-2 font-medium">
                                        <span className="sr-only">View</span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {invoices.map((invoice) => (
                                    <tr key={invoice.id} className="border-b border-border-soft last:border-0">
                                        <td className="py-2 pr-4 text-text-2">
                                            {invoice.date ? new Date(invoice.date).toLocaleDateString() : '—'}
                                        </td>
                                        {/* Cifre tabulare — `.ai/rules/frontend.md` */}
                                        <td className="numeric py-2 pr-4 text-text">{invoice.total}</td>
                                        <td className="py-2 pr-4 text-text-2">{invoice.status ?? '—'}</td>
                                        <td className="py-2">
                                            {invoice.hostedUrl && (
                                                <a
                                                    href={invoice.hostedUrl}
                                                    target="_blank"
                                                    rel="noreferrer"
                                                    className="text-accent-text underline-offset-2 hover:underline"
                                                >
                                                    {/* SC 2.4.4 — „View" identic pe fiecare rând. */}
                                                    View
                                                    <span className="sr-only">
                                                        {' '}
                                                        the invoice from{' '}
                                                        {invoice.date ? new Date(invoice.date).toLocaleDateString() : 'an unknown date'}
                                                    </span>
                                                </a>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}
                </div>
            </div>
        </>
    );
}

BillingIndex.layout = (page: ReactNode) => <AppLayout>{page}</AppLayout>;
