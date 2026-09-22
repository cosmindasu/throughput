import { Head, Link, router, useForm, usePage, usePoll } from '@inertiajs/react';
import { useEffect, useId, useRef, useState, type FormEvent, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import Button from '@/Components/Button';
import ConfirmDialog from '@/Components/ConfirmDialog';
import Field, { controlClass } from '@/Components/Form/Field';
import HistoryTab from '@/Components/History/HistoryTab';
import PageHeader from '@/Components/PageHeader';
import StatusBadge, { type BadgeTone } from '@/Components/StatusBadge';
import AppLayout from '@/Layouts/AppLayout';
import { useLocale } from '@/hooks/useLocale';
import { formatDate, formatDateTime } from '@/lib/format';
import { formatMoney } from '@/lib/money';
import type { InvoicesShowPageProps, InvoiceStatus, Payment, PaymentMethod } from '@/types/generated';

const STATUS_TONE: Record<InvoiceStatus, BadgeTone> = {
    draft: 'neutral',
    sent: 'accent',
    paid: 'success',
    overdue: 'danger',
    void: 'neutral',
};

const PAYMENT_METHOD_VALUES: PaymentMethod[] = ['bank_transfer', 'check', 'manual'];

interface PaymentFormData {
    amount: string;
    method: PaymentMethod;
    paid_at: string;
}

/**
 * `Invoices/Show` — detaliul unei facturi (US-BILL-01/02, §12.1). „Mark as sent",
 * „Void" (cu motiv), încasare parțială/completă și starea PDF-ului cu polling — ADR-013,
 * exact tiparul deja validat de `ShipmentsSection` (`usePoll` cu ramura `start()`
 * obligatorie, `.ai/rules/frontend.md`).
 */
export default function Show() {
    const { t } = useTranslation('invoices');
    const locale = useLocale();
    const { invoice } = usePage<InvoicesShowPageProps>().props;
    const { workspace } = usePage().props;
    const base = workspace ? `/${workspace.slug}` : '';

    const [sending, setSending] = useState(false);
    const [voiding, setVoiding] = useState(false);
    const [voidReason, setVoidReason] = useState('');
    const [voidError, setVoidError] = useState<string | null>(null);
    const [processing, setProcessing] = useState(false);
    const [actionError, setActionError] = useState<string | null>(null);

    // Review a11y (P1) — declanșatorul „Mark as sent" DISPARE la succes (`InvoicePolicy::
    // update()` are gardă de stare, `draft` only), iar `<dialog>` n-are unde întoarce focusul.
    // Element STABIL, tabIndex={-1}, ca `headingRef` din `ShipmentsSection` — focalizat
    // explicit după succes, niciodată lăsat pe `<body>`.
    const detailsHeadingId = useId();
    const detailsHeadingRef = useRef<HTMLHeadingElement>(null);

    // Review a11y (P1) — un „Void" refuzat (motiv gol) mută focusul înapoi pe textarea, ca
    // eticheta + eroarea ei (legate prin `Field`) să fie anunțate imediat, nu doar disponibile
    // la o navigare ulterioară cu Tab.
    const voidTextareaRef = useRef<HTMLTextAreaElement>(null);

    // ADR-013 — „butonul de descărcare e activ doar pe `ready`; pe `failed` motivul + un
    // buton de reîncercare". Polling DOAR cât timp `pending`, ca la eticheta de curierat.
    const isPending = invoice.pdfStatus === 'pending';
    const { start, stop } = usePoll(3000, { only: ['invoice'] }, { autoStart: isPending });

    useEffect(() => {
        if (isPending) {
            start();
        } else {
            stop();
        }
    }, [isPending, start, stop]);

    const submitMarkSent = () => {
        setProcessing(true);
        setActionError(null);

        router.patch(
            `${base}/invoices/${invoice.id}/send`,
            {},
            {
                onSuccess: () => {
                    setSending(false);
                    // Review a11y (P1) — declanșatorul tocmai a dispărut (`can.markSent`
                    // devine `false`); mutăm focusul pe un element STABIL, în loc să-l lăsăm
                    // pe browser să-l ducă pe `<body>`.
                    requestAnimationFrame(() => detailsHeadingRef.current?.focus());
                },
                onError: (errors) => setActionError(Object.values(errors)[0] ?? t('invoices:show.markSentDialog.error')),
                onFinish: () => setProcessing(false),
            },
        );
    };

    const focusVoidReason = () => {
        requestAnimationFrame(() => voidTextareaRef.current?.focus());
    };

    const submitVoid = () => {
        setProcessing(true);
        setVoidError(null);

        router.patch(
            `${base}/invoices/${invoice.id}/void`,
            { reason: voidReason },
            {
                onSuccess: () => {
                    setVoiding(false);
                    setVoidReason('');
                },
                onError: (errors) => {
                    setVoidError(Object.values(errors)[0] ?? t('invoices:show.voidDialog.error'));
                    focusVoidReason();
                },
                onFinish: () => setProcessing(false),
            },
        );
    };

    /**
     * Review a11y (P1) — declanșatorul rămâne MONTAT necondiționat (`onConfirm` din
     * `ConfirmDialog` primește mereu o funcție reală, niciodată `undefined`), iar
     * verificarea „motivul e gol" devine un handler INERT (nu trimite cererea, dar mută
     * focusul pe câmp, unde eticheta + eroarea legate prin `Field` se anunță imediat) —
     * altfel butonul lipsea din DOM cât timp motivul era gol, iar un cititor de ecran
     * ajungea direct la „Close", fără nicio indicație.
     */
    const attemptVoid = () => {
        if (processing) {
            return;
        }

        if (voidReason.trim() === '') {
            setVoidError(t('invoices:show.voidDialog.reasonRequired'));
            focusVoidReason();
            return;
        }

        submitVoid();
    };

    const retryPdf = () => {
        // Review a11y (P2) — `aria-disabled` nu blochează click-ul nativ; fără gardă,
        // Enter ținut apăsat sau un dublu-clic redispecerizează cererea.
        if (processing) {
            return;
        }

        setProcessing(true);
        setActionError(null);

        router.patch(
            `${base}/invoices/${invoice.id}/pdf/retry`,
            {},
            {
                onError: (errors) => setActionError(Object.values(errors)[0] ?? t('invoices:show.pdf.retryError')),
                onFinish: () => setProcessing(false),
            },
        );
    };

    const invoiceTitle = invoice.invoiceNumber ?? t('invoices:show.fallbackTitle');

    return (
        <>
            <Head title={invoiceTitle} />

            <PageHeader
                title={invoiceTitle}
                description={
                    <span className="flex flex-wrap items-center gap-2">
                        {invoice.order && (
                            <Link href={`${base}/orders/${invoice.order.id}`} className="hover:underline">
                                {invoice.order.orderNumber ?? t('invoices:show.fallbackOrder')}
                            </Link>
                        )}
                        {/* `invoice.statusLabel` vine deja tradus din backend — nu se retraduce. */}
                        <StatusBadge tone={STATUS_TONE[invoice.status]}>{invoice.statusLabel}</StatusBadge>
                    </span>
                }
                actions={
                    <>
                        {/* Primitiva `Button`: varianta scrisă de mână n-avea niciun stil de
                            focus (SC 2.4.7) — doar inelul implicit al browserului. */}
                        {invoice.can.markSent && (
                            <Button variant="primary" onClick={() => setSending(true)}>
                                {t('invoices:actions.markAsSent')}
                            </Button>
                        )}
                        {invoice.can.void && (
                            <Button variant="danger" onClick={() => setVoiding(true)}>
                                {t('invoices:actions.void')}
                            </Button>
                        )}
                    </>
                }
            />

            {actionError && (
                <p role="alert" className="mt-4 rounded-md bg-danger-tint px-3 py-2 text-sm text-danger">
                    {actionError}
                </p>
            )}

            <div className="mt-6 flex flex-col gap-6">
                {/* Review a11y (P1) — ținta de focus stabilă pentru „Mark as sent": rămâne
                    montată indiferent de `can.markSent`, spre deosebire de butonul din
                    `PageHeader` — același tipar ca `headingRef` din `ShipmentsSection`. */}
                <h2
                    id={detailsHeadingId}
                    ref={detailsHeadingRef}
                    tabIndex={-1}
                    className="text-sm font-semibold text-text focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus"
                >
                    {t('invoices:show.detailsHeading')}
                </h2>

                <dl aria-labelledby={detailsHeadingId} className="grid gap-4 rounded-lg border border-border bg-surface p-4 sm:grid-cols-2 lg:grid-cols-3">
                    <SummaryField label={t('invoices:show.summary.account')} value={invoice.order?.account?.name ?? '—'} />
                    <SummaryField label={t('invoices:show.summary.owner')} value={invoice.order?.owner?.name ?? '—'} />
                    <SummaryField
                        label={t('invoices:show.summary.issueDate')}
                        value={invoice.issueDate ? formatDate(invoice.issueDate, locale) : '—'}
                    />
                    <SummaryField
                        label={t('invoices:show.summary.dueDate')}
                        value={invoice.dueDate ? formatDate(invoice.dueDate, locale) : '—'}
                    />
                    <SummaryField
                        label={t('invoices:show.summary.total')}
                        value={<span className="numeric">{formatMoney(invoice.total, invoice.currency, locale)}</span>}
                    />
                    <SummaryField
                        label={t('invoices:show.summary.balanceDue')}
                        value={<span className="numeric">{formatMoney(invoice.balanceDue, invoice.currency, locale)}</span>}
                    />
                </dl>

                {invoice.status === 'void' && invoice.voidReason && (
                    <section aria-label={t('invoices:show.voidReasonAria')} className="rounded-lg border border-danger bg-danger-tint p-4 text-sm text-danger">
                        <p className="font-medium">
                            {invoice.voidedAt
                                ? t('invoices:show.voidedOn', { date: formatDateTime(invoice.voidedAt, locale) })
                                : t('invoices:show.voided')}
                        </p>
                        <p className="mt-1">{invoice.voidReason}</p>
                    </section>
                )}

                {/* ADR-013 — starea PDF-ului, cu polling cât timp e `pending`. Review a11y
                    (P1) — `role="status"`/`aria-live="polite"` fac tranziția `pending -> ready`
                    (mutație reală prin polling) anunțată; ramura `failed` avea deja `role="alert"`
                    pe mesaj, deci succesul era mai puțin vizibil pentru un cititor de ecran decât
                    eșecul, înainte de acest fix. */}
                <section
                    aria-label={t('invoices:show.pdf.sectionAria')}
                    role="status"
                    aria-live="polite"
                    aria-atomic="true"
                    className="flex flex-wrap items-center gap-3 rounded-lg border border-border bg-surface p-4 text-sm"
                >
                    {invoice.pdfStatus === 'ready' && (
                        <a href={`${base}/invoices/${invoice.id}/pdf`} className="font-medium text-accent-text hover:underline">
                            {t('invoices:show.pdf.download')}
                        </a>
                    )}
                    {invoice.pdfStatus === 'pending' && <span className="text-text-3">{t('invoices:show.pdf.generating')}</span>}
                    {invoice.pdfStatus === 'failed' && (
                        <>
                            <span role="alert" className="text-danger">
                                {t('invoices:show.pdf.failed')}
                            </span>
                            {invoice.can.retryPdf && (
                                <button
                                    type="button"
                                    onClick={retryPdf}
                                    aria-disabled={processing || undefined}
                                    className={`text-accent-text hover:underline ${processing ? 'cursor-not-allowed opacity-60' : ''}`}
                                >
                                    {t('invoices:show.pdf.retry')}
                                </button>
                            )}
                        </>
                    )}
                </section>

                <PaymentsSection invoice={invoice} base={base} />

                {/* FR-AUD-02, §17.3 — factura e una dintre entitățile principale cu tab
                    „History". Montat la integrarea Fazei 5: componenta și endpointul vin din
                    lotul de jurnal de activitate, pagina din cel de facturare. */}
                <section aria-label={t('invoices:show.historyAria')} className="flex flex-col gap-3">
                    <h2 className="text-sm font-medium text-text">{t('invoices:show.historyHeading')}</h2>
                    <HistoryTab entityType="invoice" entityId={invoice.id} />
                </section>
            </div>

            <ConfirmDialog
                open={sending}
                title={t('invoices:show.markSentDialog.title')}
                onClose={() => setSending(false)}
                onConfirm={submitMarkSent}
                confirmLabel={t('invoices:actions.markAsSent')}
                processing={processing}
            >
                {t('invoices:show.markSentDialog.body')}
            </ConfirmDialog>

            <ConfirmDialog
                open={voiding}
                title={t('invoices:show.voidDialog.title')}
                onClose={() => {
                    setVoiding(false);
                    setVoidError(null);
                }}
                onConfirm={attemptVoid}
                confirmLabel={t('invoices:actions.void')}
                confirmVariant="danger"
                processing={processing}
            >
                <p className="mb-2">{t('invoices:show.voidDialog.body')}</p>
                {/* Review a11y (P1) — motivul obligatoriu era comunicat DOAR vizual
                    (asteriscul din `Field` e `aria-hidden`): `required`/`aria-required`,
                    un `hint` explicit și `error` legat prin `Field` (`aria-invalid`/
                    `aria-describedby`) fac regula audibilă. Butonul „Void" rămâne MONTAT
                    necondiționat (`onConfirm={attemptVoid}` mai sus) — cu motivul gol,
                    `attemptVoid` e un handler INERT (nu trimite cererea, dar mută focusul
                    pe câmp, unde eticheta + eroarea se anunță), nu un buton absent din DOM. */}
                <Field
                    label={t('invoices:show.voidDialog.reasonLabel')}
                    required
                    hint={t('invoices:show.voidDialog.reasonHint')}
                    error={voidError ?? undefined}
                >
                    {(control) => (
                        <textarea
                            {...control}
                            ref={voidTextareaRef}
                            required
                            aria-required="true"
                            className={controlClass}
                            rows={3}
                            value={voidReason}
                            onChange={(event) => setVoidReason(event.target.value)}
                        />
                    )}
                </Field>
            </ConfirmDialog>
        </>
    );
}

function SummaryField({ label, value }: { label: string; value: ReactNode }) {
    return (
        <div>
            <dt className="text-xs font-medium text-text-3">{label}</dt>
            <dd className="mt-0.5 text-sm text-text">{value}</dd>
        </div>
    );
}

/**
 * US-BILL-02 — lista încasărilor + formularul de înregistrare. Vizibil doar când
 * `can.registerPayment` (Owner/Manager, §7.4) — Agentul/Viewer văd doar lista, dacă
 * `payments.view` le e acordat (Viewer da, Agent nu — matricea are „—" la Agent).
 */
/** `bank_transfer` → `bankTransfer` — chei de catalog camelCase pentru valorile enum ale metodei de plată. */
function paymentMethodKey(method: PaymentMethod): string {
    return method.replace(/_([a-z])/g, (_, letter: string) => letter.toUpperCase());
}

function PaymentsSection({ invoice, base }: { invoice: InvoicesShowPageProps['invoice']; base: string }) {
    const { t } = useTranslation('invoices');
    const locale = useLocale();

    const { data, setData, post, processing, errors, reset } = useForm<PaymentFormData>({
        amount: '',
        method: 'bank_transfer',
        paid_at: new Date().toISOString().slice(0, 10),
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();

        // Review a11y (P2) — `aria-disabled` pe butonul de mai jos nu blochează click-ul
        // nativ (nici Enter ținut apăsat pe un câmp al formularului); gardă explicită, ca
        // la `BillingSection::createInvoice()`.
        if (processing) {
            return;
        }

        post(`${base}/invoices/${invoice.id}/payments`, {
            preserveScroll: true,
            onSuccess: () => reset('amount'),
        });
    };

    const canRegister = invoice.can.registerPayment && (invoice.status === 'sent' || invoice.status === 'overdue');

    return (
        <section aria-label={t('invoices:show.payments.sectionAria')} className="flex flex-col gap-3 rounded-lg border border-border bg-surface p-4">
            <h2 className="text-sm font-semibold text-text">{t('invoices:show.payments.heading')}</h2>

            {invoice.payments.length === 0 ? (
                <p className="text-sm text-text-3">{t('invoices:show.payments.empty')}</p>
            ) : (
                <ul className="flex flex-col gap-2 text-sm">
                    {invoice.payments.map((payment: Payment) => (
                        <li key={payment.id} className="flex flex-wrap items-center justify-between gap-2 rounded-md border border-border-soft px-3 py-2">
                            <span className="numeric font-medium">{formatMoney(payment.amount, invoice.currency, locale)}</span>
                            {/* `payment.methodLabel` vine tradus din backend (`PaymentResource`, catalogul
                                `lang/{en,fr}/enums.php` → `payment_method`) — nu se retraduce aici. Până la
                                Lotul I18N Val 5, `PaymentResource` avea un array PHP hardcodat în engleză și
                                acest comentariu era FALS; verifică `payment_method` din `enums.php` dacă
                                textul reapare netradus. */}
                            <span className="text-text-2">{payment.methodLabel}</span>
                            <span className="text-text-3">{payment.paidAt ? formatDate(payment.paidAt, locale) : '—'}</span>
                            <span className="text-text-3">{payment.createdBy?.name ?? '—'}</span>
                        </li>
                    ))}
                </ul>
            )}

            {canRegister && (
                <form onSubmit={submit} className="flex flex-wrap items-end gap-3 border-t border-border-soft pt-3">
                    <Field label={t('invoices:show.payments.amount.label')} error={errors.amount} required>
                        {(control) => (
                            <input
                                {...control}
                                type="number"
                                step="0.01"
                                min="0.01"
                                className={`${controlClass} numeric w-32`}
                                value={data.amount}
                                onChange={(event) => setData('amount', event.target.value)}
                            />
                        )}
                    </Field>

                    <Field label={t('invoices:show.payments.method.label')} error={errors.method} required>
                        {(control) => (
                            <select
                                {...control}
                                className={controlClass}
                                value={data.method}
                                onChange={(event) => setData('method', event.target.value as PaymentMethod)}
                            >
                                {PAYMENT_METHOD_VALUES.map((method) => (
                                    <option key={method} value={method}>
                                        {t(`invoices:show.payments.methodOptions.${paymentMethodKey(method)}`)}
                                    </option>
                                ))}
                            </select>
                        )}
                    </Field>

                    <Field label={t('invoices:show.payments.paidAt.label')} error={errors.paid_at} required>
                        {(control) => (
                            <input
                                {...control}
                                type="date"
                                className={controlClass}
                                value={data.paid_at}
                                onChange={(event) => setData('paid_at', event.target.value)}
                            />
                        )}
                    </Field>

                    <Button type="submit" variant="primary" pending={processing} pendingLabel={t('invoices:show.payments.submitting')}>
                        {t('invoices:show.payments.submit')}
                    </Button>
                </form>
            )}
        </section>
    );
}

Show.layout = (page: ReactNode) => <AppLayout>{page}</AppLayout>;
