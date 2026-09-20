import { Head, Link, router, useForm, usePage, usePoll } from '@inertiajs/react';
import { useEffect, useId, useRef, useState, type FormEvent, type ReactNode } from 'react';
import Button from '@/Components/Button';
import ConfirmDialog from '@/Components/ConfirmDialog';
import Field, { controlClass } from '@/Components/Form/Field';
import HistoryTab from '@/Components/History/HistoryTab';
import PageHeader from '@/Components/PageHeader';
import StatusBadge, { type BadgeTone } from '@/Components/StatusBadge';
import AppLayout from '@/Layouts/AppLayout';
import { formatMoney } from '@/lib/money';
import type { InvoicesShowPageProps, InvoiceStatus, Payment, PaymentMethod } from '@/types/generated';

const dateFormatter = new Intl.DateTimeFormat('en-US', { dateStyle: 'medium' });
const dateTimeFormatter = new Intl.DateTimeFormat('en-US', { dateStyle: 'medium', timeStyle: 'short' });

const STATUS_TONE: Record<InvoiceStatus, BadgeTone> = {
    draft: 'neutral',
    sent: 'accent',
    paid: 'success',
    overdue: 'danger',
    void: 'neutral',
};

const PAYMENT_METHODS: Array<{ value: PaymentMethod; label: string }> = [
    { value: 'bank_transfer', label: 'Bank transfer' },
    { value: 'check', label: 'Check' },
    { value: 'manual', label: 'Manual' },
];

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
                onError: (errors) => setActionError(Object.values(errors)[0] ?? 'This invoice could not be marked as sent.'),
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
                    setVoidError(Object.values(errors)[0] ?? 'This invoice could not be voided.');
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
            setVoidError('A reason is required to void this invoice.');
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
                onError: (errors) => setActionError(Object.values(errors)[0] ?? 'The PDF could not be regenerated.'),
                onFinish: () => setProcessing(false),
            },
        );
    };

    return (
        <>
            <Head title={invoice.invoiceNumber ?? 'Invoice'} />

            <PageHeader
                title={invoice.invoiceNumber ?? 'Invoice'}
                description={
                    <span className="flex flex-wrap items-center gap-2">
                        {invoice.order && (
                            <Link href={`${base}/orders/${invoice.order.id}`} className="hover:underline">
                                {invoice.order.orderNumber ?? 'Order'}
                            </Link>
                        )}
                        <StatusBadge tone={STATUS_TONE[invoice.status]}>{invoice.statusLabel}</StatusBadge>
                    </span>
                }
                actions={
                    <>
                        {/* Primitiva `Button`: varianta scrisă de mână n-avea niciun stil de
                            focus (SC 2.4.7) — doar inelul implicit al browserului. */}
                        {invoice.can.markSent && (
                            <Button variant="primary" onClick={() => setSending(true)}>
                                Mark as sent
                            </Button>
                        )}
                        {invoice.can.void && (
                            <Button variant="danger" onClick={() => setVoiding(true)}>
                                Void
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
                    Details
                </h2>

                <dl aria-labelledby={detailsHeadingId} className="grid gap-4 rounded-lg border border-border bg-surface p-4 sm:grid-cols-2 lg:grid-cols-3">
                    <SummaryField label="Account" value={invoice.order?.account?.name ?? '—'} />
                    <SummaryField label="Owner" value={invoice.order?.owner?.name ?? '—'} />
                    <SummaryField label="Issue date" value={invoice.issueDate ? dateFormatter.format(new Date(invoice.issueDate)) : '—'} />
                    <SummaryField label="Due date" value={invoice.dueDate ? dateFormatter.format(new Date(invoice.dueDate)) : '—'} />
                    <SummaryField label="Total" value={<span className="numeric">{formatMoney(invoice.total, invoice.currency)}</span>} />
                    <SummaryField label="Balance due" value={<span className="numeric">{formatMoney(invoice.balanceDue, invoice.currency)}</span>} />
                </dl>

                {invoice.status === 'void' && invoice.voidReason && (
                    <section aria-label="Void reason" className="rounded-lg border border-danger bg-danger-tint p-4 text-sm text-danger">
                        <p className="font-medium">Voided{invoice.voidedAt ? ` on ${dateTimeFormatter.format(new Date(invoice.voidedAt))}` : ''}</p>
                        <p className="mt-1">{invoice.voidReason}</p>
                    </section>
                )}

                {/* ADR-013 — starea PDF-ului, cu polling cât timp e `pending`. Review a11y
                    (P1) — `role="status"`/`aria-live="polite"` fac tranziția `pending -> ready`
                    (mutație reală prin polling) anunțată; ramura `failed` avea deja `role="alert"`
                    pe mesaj, deci succesul era mai puțin vizibil pentru un cititor de ecran decât
                    eșecul, înainte de acest fix. */}
                <section
                    aria-label="PDF"
                    role="status"
                    aria-live="polite"
                    aria-atomic="true"
                    className="flex flex-wrap items-center gap-3 rounded-lg border border-border bg-surface p-4 text-sm"
                >
                    {invoice.pdfStatus === 'ready' && (
                        <a href={`${base}/invoices/${invoice.id}/pdf`} className="font-medium text-accent-text hover:underline">
                            Download PDF
                        </a>
                    )}
                    {invoice.pdfStatus === 'pending' && <span className="text-text-3">Generating PDF…</span>}
                    {invoice.pdfStatus === 'failed' && (
                        <>
                            <span role="alert" className="text-danger">
                                The PDF could not be generated.
                            </span>
                            {invoice.can.retryPdf && (
                                <button
                                    type="button"
                                    onClick={retryPdf}
                                    aria-disabled={processing || undefined}
                                    className={`text-accent-text hover:underline ${processing ? 'cursor-not-allowed opacity-60' : ''}`}
                                >
                                    Retry
                                </button>
                            )}
                        </>
                    )}
                </section>

                <PaymentsSection invoice={invoice} base={base} />

                {/* FR-AUD-02, §17.3 — factura e una dintre entitățile principale cu tab
                    „History". Montat la integrarea Fazei 5: componenta și endpointul vin din
                    lotul de jurnal de activitate, pagina din cel de facturare. */}
                <section aria-label="History" className="flex flex-col gap-3">
                    <h2 className="text-sm font-medium text-text">History</h2>
                    <HistoryTab entityType="invoice" entityId={invoice.id} />
                </section>
            </div>

            <ConfirmDialog
                open={sending}
                title="Mark this invoice as sent?"
                onClose={() => setSending(false)}
                onConfirm={submitMarkSent}
                confirmLabel="Mark as sent"
                processing={processing}
            >
                This sets the due date from the account&apos;s credit terms.
            </ConfirmDialog>

            <ConfirmDialog
                open={voiding}
                title="Void this invoice?"
                onClose={() => {
                    setVoiding(false);
                    setVoidError(null);
                }}
                onConfirm={attemptVoid}
                confirmLabel="Void"
                confirmVariant="danger"
                processing={processing}
            >
                <p className="mb-2">This cannot be undone — any payments already recorded stay on the record.</p>
                {/* Review a11y (P1) — motivul obligatoriu era comunicat DOAR vizual
                    (asteriscul din `Field` e `aria-hidden`): `required`/`aria-required`,
                    un `hint` explicit și `error` legat prin `Field` (`aria-invalid`/
                    `aria-describedby`) fac regula audibilă. Butonul „Void" rămâne MONTAT
                    necondiționat (`onConfirm={attemptVoid}` mai sus) — cu motivul gol,
                    `attemptVoid` e un handler INERT (nu trimite cererea, dar mută focusul
                    pe câmp, unde eticheta + eroarea se anunță), nu un buton absent din DOM. */}
                <Field label="Reason" required hint="Explain why this invoice is voided — it stays on the record for anyone who opens it later." error={voidError ?? undefined}>
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
function PaymentsSection({ invoice, base }: { invoice: InvoicesShowPageProps['invoice']; base: string }) {
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
        <section aria-label="Payments" className="flex flex-col gap-3 rounded-lg border border-border bg-surface p-4">
            <h2 className="text-sm font-semibold text-text">Payments</h2>

            {invoice.payments.length === 0 ? (
                <p className="text-sm text-text-3">No payments recorded yet.</p>
            ) : (
                <ul className="flex flex-col gap-2 text-sm">
                    {invoice.payments.map((payment: Payment) => (
                        <li key={payment.id} className="flex flex-wrap items-center justify-between gap-2 rounded-md border border-border-soft px-3 py-2">
                            <span className="numeric font-medium">{formatMoney(payment.amount, invoice.currency)}</span>
                            <span className="text-text-2">{payment.methodLabel}</span>
                            <span className="text-text-3">{payment.paidAt ? dateFormatter.format(new Date(payment.paidAt)) : '—'}</span>
                            <span className="text-text-3">{payment.createdBy?.name ?? '—'}</span>
                        </li>
                    ))}
                </ul>
            )}

            {canRegister && (
                <form onSubmit={submit} className="flex flex-wrap items-end gap-3 border-t border-border-soft pt-3">
                    <Field label="Amount" error={errors.amount} required>
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

                    <Field label="Method" error={errors.method} required>
                        {(control) => (
                            <select
                                {...control}
                                className={controlClass}
                                value={data.method}
                                onChange={(event) => setData('method', event.target.value as PaymentMethod)}
                            >
                                {PAYMENT_METHODS.map((method) => (
                                    <option key={method.value} value={method.value}>
                                        {method.label}
                                    </option>
                                ))}
                            </select>
                        )}
                    </Field>

                    <Field label="Paid at" error={errors.paid_at} required>
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

                    <Button type="submit" variant="primary" pending={processing} pendingLabel="Recording…">
                        Record payment
                    </Button>
                </form>
            )}
        </section>
    );
}

Show.layout = (page: ReactNode) => <AppLayout>{page}</AppLayout>;
