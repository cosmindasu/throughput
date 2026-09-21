import { Head, router, useForm, usePage } from '@inertiajs/react';
import type { FormEvent, ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import AccountCombobox from '@/Components/AccountCombobox';
import Button, { ButtonLink } from '@/Components/Button';
import Field, { controlClass } from '@/Components/Form/Field';
import OrderLinesEditor, { type OrderLineFormRow } from '@/Components/Orders/OrderLinesEditor';
import PageHeader from '@/Components/PageHeader';
import AppLayout from '@/Layouts/AppLayout';
import type { OrdersCreatePageProps } from '@/types/generated';

const CURRENCIES = ['USD', 'EUR', 'GBP'] as const;

interface OrderFormData {
    account_id: string;
    contact_id: string;
    currency: string;
    notes: string;
    owner_user_id: string;
    lines: OrderLineFormRow[];
}

/** Toate mesajele de eroare ale liniilor (`lines.0.quantity`, …), într-un singur șir. */
function lineErrorsFrom(errors: Partial<Record<string, string>>): string | undefined {
    const messages = Object.entries(errors)
        .filter(([key]) => key.startsWith('lines'))
        .map(([, message]) => message)
        .filter((message): message is string => Boolean(message));

    return messages.length > 0 ? Array.from(new Set(messages)).join(' ') : undefined;
}

/**
 * US-ORD-01 — precompletat din `?account=` când vine din pagina unui cont, altfel
 * pornește gol (`AccountCombobox`, același tipar ca `Deals/Create.tsx`). Liniile
 * inițiale sunt opționale (§9 task): un draft se poate salva fără nicio linie și
 * primi linii mai târziu din „Edit".
 */
export default function Create() {
    const { t } = useTranslation('orders');
    const { account, contacts, owners, can, workspace } = usePage<OrdersCreatePageProps>().props;
    const workspaceSlug = workspace?.slug ?? '';
    const basePath = `/${workspaceSlug}/orders/create`;

    const { data, setData, post, processing, errors } = useForm<OrderFormData>({
        account_id: account?.id ?? '',
        contact_id: '',
        currency: 'USD',
        notes: '',
        owner_user_id: '',
        lines: [],
    });

    const handleAccountChange = (accountId: string | null) => {
        setData((current) => ({ ...current, account_id: accountId ?? '', contact_id: '' }));

        router.get(basePath, { account: accountId ?? '' }, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();

        // Butonul de submit e `aria-disabled`, nu `disabled` nativ (`Button`, prop
        // `pending`) — al doilea submit se oprește AICI, nu de browser.
        if (processing) {
            return;
        }

        post(`/${workspaceSlug}/orders`);
    };

    return (
        <>
            <Head title={t('create.title')} />

            <div className="flex flex-col gap-6">
                <PageHeader title={t('create.title')} description={account ? t('create.descriptionForAccount', { account: account.name }) : undefined} />

                <form onSubmit={submit} className="flex flex-col gap-4">
                    <div className="flex max-w-xl flex-col gap-4 rounded-lg border border-border bg-surface p-4">
                        <Field label={t('create.fields.account.label')} error={errors.account_id} required>
                            {(control) => (
                                <AccountCombobox
                                    {...control}
                                    value={data.account_id.trim() === '' ? null : data.account_id}
                                    initialLabel={account?.name ?? null}
                                    onChange={handleAccountChange}
                                />
                            )}
                        </Field>

                        <Field label={t('create.fields.contact.label')} error={errors.contact_id}>
                            {(control) => (
                                <select
                                    {...control}
                                    className={controlClass}
                                    value={data.contact_id}
                                    onChange={(event) => setData('contact_id', event.target.value)}
                                >
                                    <option value="">{t('create.fields.contact.none')}</option>
                                    {contacts.map((contact) => (
                                        <option key={contact.id} value={contact.id}>
                                            {contact.name}
                                        </option>
                                    ))}
                                </select>
                            )}
                        </Field>

                        <Field label={t('create.fields.currency.label')} error={errors.currency}>
                            {(control) => (
                                <select
                                    {...control}
                                    className={controlClass}
                                    value={data.currency}
                                    onChange={(event) => setData('currency', event.target.value)}
                                >
                                    {CURRENCIES.map((currency) => (
                                        <option key={currency} value={currency}>
                                            {currency}
                                        </option>
                                    ))}
                                </select>
                            )}
                        </Field>

                        <Field label={t('create.fields.notes.label')} error={errors.notes}>
                            {(control) => (
                                <textarea
                                    {...control}
                                    className={controlClass}
                                    rows={3}
                                    value={data.notes}
                                    onChange={(event) => setData('notes', event.target.value)}
                                />
                            )}
                        </Field>

                        {can.changeOwner && (
                            <Field label={t('create.fields.owner.label')} error={errors.owner_user_id} hint={t('create.fields.owner.hint')}>
                                {(control) => (
                                    <select
                                        {...control}
                                        className={controlClass}
                                        value={data.owner_user_id}
                                        onChange={(event) => setData('owner_user_id', event.target.value)}
                                    >
                                        <option value="">{t('create.fields.owner.meOption')}</option>
                                        {owners.map((owner) => (
                                            <option key={owner.id} value={owner.id}>
                                                {owner.name}
                                            </option>
                                        ))}
                                    </select>
                                )}
                            </Field>
                        )}
                    </div>

                    <section aria-label={t('create.lines.ariaLabel')} className="flex flex-col gap-3 rounded-lg border border-border bg-surface p-4">
                        <h2 className="text-sm font-medium text-text-2">{t('create.lines.heading')}</h2>
                        <OrderLinesEditor
                            currency={data.currency}
                            lines={data.lines}
                            onChange={(lines) => setData('lines', lines)}
                            error={lineErrorsFrom(errors)}
                        />
                    </section>

                    <div className="flex items-center gap-2">
                        {/* Primitiva `Button`: clasele erau copiate de mână, iar `disabled`
                            nativ pe butonul apăsat îl blurează și aruncă focusul pe `<body>`. */}
                        <Button type="submit" variant="primary" pending={processing} pendingLabel={t('create.actions.submitPending')}>
                            {t('create.actions.submit')}
                        </Button>
                        <ButtonLink href={account ? `/${workspaceSlug}/accounts/${account.id}` : `/${workspaceSlug}/orders`}>
                            {t('create.actions.cancel')}
                        </ButtonLink>
                    </div>
                </form>
            </div>
        </>
    );
}

Create.layout = (page: ReactNode) => <AppLayout>{page}</AppLayout>;
