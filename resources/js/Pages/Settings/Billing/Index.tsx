import { Head, router, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import type { TFunction } from 'i18next';
import Button from '@/Components/Button';
import PageHeader from '@/Components/PageHeader';
import StatusBadge, { type BadgeTone } from '@/Components/StatusBadge';
import { useLocale } from '@/hooks/useLocale';
import AppLayout from '@/Layouts/AppLayout';
import { formatDateNumeric } from '@/lib/format';
import type { BillingIndexPageProps } from '@/types/generated';

const STATUS_TONE: Record<string, BadgeTone> = {
    active: 'success',
    past_due: 'warning',
    unpaid: 'danger',
    canceled: 'danger',
};

/**
 * Chei explicite pentru statusurile Stripe cunoscute — restul (trialing, incomplete…)
 * cad pe formatarea generică anterioară (`replace(/_/g, ' ')`), netradusă: o enumerare
 * completă a vocabularului Stripe ar depăși scopul Val 3 pentru un status marginal, rar
 * întâlnit în demo.
 */
// Valorile din catalog sunt cu MINUSCULĂ, deliberat: înaintea acestui val ecranul randa
// `status.replace(/_/g, ' ')` peste statusul Stripe brut, adică „past due", nu „Past due".
// `e2e/specs/stripe-webhook-idempotency.spec.ts:101` caută `'past due'` cu `{ exact: true }`.
// Capitalizarea ar fi o îmbunătățire reală — dar e o decizie de QA vizual (Val 5), nu ceva
// de strecurat într-o extragere de string-uri, care prin definiție nu schimbă engleza.
const STATUS_LABEL_KEYS: Record<string, string> = {
    active: 'settings:billing.status.active',
    past_due: 'settings:billing.status.pastDue',
    unpaid: 'settings:billing.status.unpaid',
    canceled: 'settings:billing.status.canceled',
};

function statusLabel(status: string | null, t: TFunction<'settings'>): string {
    if (status === null) {
        return t('settings:billing.noStatus');
    }

    const key = STATUS_LABEL_KEYS[status];

    return key ? t(key) : status.replace(/_/g, ' ');
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
    const { t } = useTranslation('settings');
    const locale = useLocale();

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
                <Head title={t('settings:billing.canceledTitle')} />

                <div className="flex flex-col items-start gap-4 rounded-lg border border-border bg-surface p-6">
                    <StatusBadge tone="danger">{t('settings:billing.canceledTitle')}</StatusBadge>
                    <p className="text-sm text-text-2">{t('settings:billing.canceledBody')}</p>
                    {subscription.canceledAt && (
                        <p className="text-xs text-text-2">
                            {t('settings:billing.canceledOn', { date: formatDateNumeric(subscription.canceledAt, locale) })}
                        </p>
                    )}
                    {can.manage && (
                        <Button variant="primary" onClick={openPortal}>
                            {t('settings:billing.reactivate')}
                        </Button>
                    )}
                </div>
            </>
        );
    }

    return (
        <>
            <Head title={t('settings:billing.title')} />

            <div className="flex flex-col gap-6">
                <PageHeader title={t('settings:billing.title')} />

                <div className="flex flex-col gap-4 rounded-lg border border-border bg-surface p-4">
                    <div className="flex items-center justify-between gap-4">
                        <div>
                            <h2 className="text-sm font-medium text-text">{t('settings:billing.currentPlan')}</h2>
                            <p className="mt-1 text-sm text-text-2">
                                {subscription.plan ?? t('settings:billing.noActiveSubscription')}
                            </p>
                        </div>
                        <StatusBadge tone={STATUS_TONE[subscription.status ?? ''] ?? 'neutral'}>
                            {statusLabel(subscription.status, t)}
                        </StatusBadge>
                    </div>

                    <div>
                        <h2 className="text-sm font-medium text-text">{t('settings:billing.paymentMethod')}</h2>
                        <p className="mt-1 text-sm text-text-2">
                            {subscription.paymentMethod
                                ? t('settings:billing.paymentMethodValue', {
                                      type: subscription.paymentMethod.type,
                                      lastFour: subscription.paymentMethod.lastFour ?? '????',
                                  })
                                : t('settings:billing.noPaymentMethod')}
                        </p>
                    </div>

                    {can.manage && (
                        <div>
                            <Button variant="secondary" onClick={openPortal}>
                                {t('settings:billing.manageButton')}
                            </Button>
                        </div>
                    )}
                </div>

                <div className="flex flex-col gap-3 rounded-lg border border-border bg-surface p-4">
                    <h2 id="invoice-history-heading" className="text-sm font-medium text-text">
                        {t('settings:billing.invoiceHistory')}
                    </h2>

                    {invoices.length === 0 ? (
                        <p className="text-sm text-text-2">{t('settings:billing.noInvoices')}</p>
                    ) : (
                        /* `aria-labelledby` către headingul vizibil dedicat de deasupra, nu un
                           `<caption>` separat — excepția din `.ai/rules/frontend.md`. Tabelul
                           n-avea nici nume, nici `scope` pe celulele de antet: singurul din
                           aplicație cu `<th>` fără `scope="col"` (SC 1.3.1 — relația
                           antet/celulă e chiar ce cere criteriul). */
                        <table className="data-table w-full text-left text-sm" aria-labelledby="invoice-history-heading">
                            <thead>
                                <tr className="border-b border-border-soft text-text-2">
                                    <th scope="col" className="py-2 pr-4 font-medium">{t('settings:billing.columns.date')}</th>
                                    <th scope="col" className="py-2 pr-4 font-medium">{t('settings:billing.columns.total')}</th>
                                    <th scope="col" className="py-2 pr-4 font-medium">{t('settings:billing.columns.status')}</th>
                                    <th scope="col" className="py-2 font-medium">
                                        <span className="sr-only">{t('settings:billing.columns.view')}</span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {invoices.map((invoice) => (
                                    <tr key={invoice.id} className="border-b border-border-soft last:border-0">
                                        <td className="py-2 pr-4 text-text-2">
                                            {invoice.date ? formatDateNumeric(invoice.date, locale) : '—'}
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
                                                    {t('settings:billing.viewInvoice')}
                                                    <span className="sr-only">
                                                        {' '}
                                                        {t('settings:billing.viewInvoiceSrLabel', {
                                                            date: invoice.date
                                                                ? formatDateNumeric(invoice.date, locale)
                                                                : t('settings:billing.unknownDate'),
                                                        })}
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
