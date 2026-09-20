import { Head, router, useForm, usePage } from '@inertiajs/react';
import type { FormEvent, ReactNode } from 'react';
import AccountCombobox from '@/Components/AccountCombobox';
import Button, { ButtonLink } from '@/Components/Button';
import Field, { controlClass } from '@/Components/Form/Field';
import OrderLinesEditor, { type OrderLineFormRow } from '@/Components/Orders/OrderLinesEditor';
import PageHeader from '@/Components/PageHeader';
import AppLayout from '@/Layouts/AppLayout';
import type { Order, OrdersEditPageProps } from '@/types/generated';

const CURRENCIES = ['USD', 'EUR', 'GBP'] as const;

interface OrderFormData {
    account_id: string;
    contact_id: string;
    currency: string;
    notes: string;
    owner_user_id: string;
    lines: OrderLineFormRow[];
}

function lineErrorsFrom(errors: Partial<Record<string, string>>): string | undefined {
    const messages = Object.entries(errors)
        .filter(([key]) => key.startsWith('lines'))
        .map(([, message]) => message)
        .filter((message): message is string => Boolean(message));

    return messages.length > 0 ? Array.from(new Set(messages)).join(' ') : undefined;
}

function linesFromOrder(order: Order): OrderLineFormRow[] {
    return order.lines.map((line) => ({
        key: line.id,
        variant_id: line.variantId,
        label: line.sku ? `${line.description} · ${line.sku}` : line.description,
        quantity: String(line.quantity),
        unit_price: String(line.unitPrice),
        discount: String(line.discount),
        // Necunoscut fără o nouă căutare — `OrderLinesEditor` doar omite avertismentul
        // vizual pentru liniile deja existente, server-ul tot revalidează la confirmare.
        available: null,
    }));
}

/**
 * `OrderPolicy::update()` a permis deja accesul doar pe comenzi `draft` — un draft
 * poate schimba contul, contactul, notițele, owner-ul și liniile complet (§9 task
 * punctul 1), la fel ca `Deals/Edit.tsx`.
 */
export default function Edit() {
    const { order, account, contacts, owners, can, workspace } = usePage<OrdersEditPageProps>().props;
    const workspaceSlug = workspace?.slug ?? '';
    const basePath = `/${workspaceSlug}/orders/${order.id}/edit`;

    const { data, setData, put, processing, errors } = useForm<OrderFormData>({
        account_id: account?.id ?? '',
        contact_id: order.contact?.id ?? '',
        currency: order.currency,
        notes: order.notes ?? '',
        owner_user_id: '',
        lines: linesFromOrder(order),
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

        put(`/${workspaceSlug}/orders/${order.id}`);
    };

    return (
        <>
            <Head title={`Edit ${order.orderNumber ?? 'order'}`} />

            <div className="flex flex-col gap-6">
                <PageHeader title="Edit order" description={account ? `For ${account.name}` : undefined} />

                <form onSubmit={submit} className="flex flex-col gap-4">
                    <div className="flex max-w-xl flex-col gap-4 rounded-lg border border-border bg-surface p-4">
                        <Field label="Account" error={errors.account_id} required>
                            {(control) => (
                                <AccountCombobox
                                    {...control}
                                    value={data.account_id.trim() === '' ? null : data.account_id}
                                    initialLabel={account?.name ?? null}
                                    onChange={handleAccountChange}
                                />
                            )}
                        </Field>

                        <Field label="Contact" error={errors.contact_id}>
                            {(control) => (
                                <select
                                    {...control}
                                    className={controlClass}
                                    value={data.contact_id}
                                    onChange={(event) => setData('contact_id', event.target.value)}
                                >
                                    <option value="">None</option>
                                    {order.contact && !contacts.some((contact) => contact.id === order.contact?.id) && (
                                        <option value={order.contact.id}>
                                            {order.contact.isAnonymized ? 'Anonymized contact' : order.contact.name}
                                        </option>
                                    )}
                                    {contacts.map((contact) => (
                                        <option key={contact.id} value={contact.id}>
                                            {contact.name}
                                        </option>
                                    ))}
                                </select>
                            )}
                        </Field>

                        <Field label="Currency" error={errors.currency}>
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

                        <Field label="Notes" error={errors.notes}>
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
                            <Field label="Owner" error={errors.owner_user_id} hint="Leave as “Me” to keep the current owner.">
                                {(control) => (
                                    <select
                                        {...control}
                                        className={controlClass}
                                        value={data.owner_user_id}
                                        onChange={(event) => setData('owner_user_id', event.target.value)}
                                    >
                                        <option value="">{order.owner.name}</option>
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

                    <section aria-label="Order lines" className="flex flex-col gap-3 rounded-lg border border-border bg-surface p-4">
                        <h2 className="text-sm font-medium text-text-2">Lines</h2>
                        <OrderLinesEditor
                            currency={data.currency}
                            lines={data.lines}
                            onChange={(lines) => setData('lines', lines)}
                            error={lineErrorsFrom(errors)}
                        />
                    </section>

                    <div className="flex items-center gap-2">
                        <Button type="submit" variant="primary" pending={processing} pendingLabel="Saving…">
                            Save changes
                        </Button>
                        <ButtonLink href={`/${workspaceSlug}/orders/${order.id}`}>Cancel</ButtonLink>
                    </div>
                </form>
            </div>
        </>
    );
}

Edit.layout = (page: ReactNode) => <AppLayout>{page}</AppLayout>;
