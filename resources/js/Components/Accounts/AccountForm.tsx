import { useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import Button from '@/Components/Button';
import DuplicateEmailNotice from '@/Components/DuplicateEmailNotice';
import Field, { controlClass } from '@/Components/Form/Field';
import type { AccountDetail, AccountOwnerOption, CreditTerms } from '@/types/generated';

interface AccountFormProps {
    mode: 'create' | 'edit';
    account?: AccountDetail;
    owners: AccountOwnerOption[];
    prefillName?: string;
    action: string;
}

interface AccountFormData {
    name: string;
    domain: string;
    industry: string;
    phone: string;
    owner_user_id: string;
    status: string;
    credit_terms: CreditTerms;
    source: string;
    tags: string;
    billing_address: { line1: string; city: string; state: string; postal_code: string; country: string };
    shipping_address: { line1: string; city: string; state: string; postal_code: string; country: string };
    contact: { first_name: string; last_name: string; email: string; phone: string; title: string };
    confirm_duplicate_email: boolean;
}

const emptyAddress = { line1: '', city: '', state: '', postal_code: '', country: '' };

/**
 * Formular comun `Accounts/Create` și `Accounts/Edit` (US-CRM-01, FR-CRM-01). Secțiunea
 * de contact principal există DOAR la creare — editarea contactelor e alt pachet (CRUD
 * propriu pe `/contacts`), deci `mode === 'edit'` nu trimite deloc câmpul `contact`.
 */
export default function AccountForm({ mode, account, owners, prefillName, action }: AccountFormProps) {
    const [confirmDuplicate, setConfirmDuplicate] = useState(false);

    const { data, setData, post, put, transform, processing, errors } = useForm<AccountFormData>({
        name: account?.name ?? prefillName ?? '',
        domain: account?.domain ?? '',
        industry: account?.industry ?? '',
        phone: account?.phone ?? '',
        owner_user_id: account?.owner?.id ?? '',
        status: account?.status ?? 'prospect',
        credit_terms: account?.creditTerms ?? 'net_30',
        source: account?.source ?? '',
        tags: account?.tags?.join(', ') ?? '',
        billing_address: account?.billingAddress
            ? {
                  line1: account.billingAddress.line1 ?? '',
                  city: account.billingAddress.city ?? '',
                  state: account.billingAddress.state ?? '',
                  postal_code: account.billingAddress.postalCode ?? '',
                  country: account.billingAddress.country ?? '',
              }
            : emptyAddress,
        shipping_address: account?.shippingAddress
            ? {
                  line1: account.shippingAddress.line1 ?? '',
                  city: account.shippingAddress.city ?? '',
                  state: account.shippingAddress.state ?? '',
                  postal_code: account.shippingAddress.postalCode ?? '',
                  country: account.shippingAddress.country ?? '',
              }
            : emptyAddress,
        contact: { first_name: '', last_name: '', email: '', phone: '', title: '' },
        confirm_duplicate_email: false,
    });

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();

        // `tags` circulă în formular ca text („comma-separated", mai simplu de tastat
        // decât un editor de chip-uri) — transformat în array doar la trimitere, ca
        // serverul (`tags.*` => string) să nu vadă niciodată diferența.
        //
        // `contact` — bug găsit prin E2E (`e2e/specs/global-search.spec.ts`), reparat aici:
        // `data.contact` e mereu un obiect (chiar și necompletat, cu string-uri goale),
        // deci `contact` ajunge mereu PREZENT în payload. `StoreAccountRequest` cere
        // `contact.first_name`/`contact.last_name` cu `required_with:contact` — regula
        // citește corect „secțiunea de contact a fost atinsă", dar „prezent" pentru
        // `FormRequest` înseamnă „cheia există", nu „are o valoare utilă". Rezultat: orice
        // creare de cont FĂRĂ contact principal (calea comună, contactul e opțional)
        // eșua cu 422 direct din formularul real — nu dintr-o eroare de test. Cheia
        // `contact` se omite acum din payload când ambele nume sunt goale, exact cazul
        // „nu completez contactul", ca `required_with:contact` să nu se mai declanșeze.
        const { first_name: contactFirstName, last_name: contactLastName } = data.contact;
        const hasContact = mode === 'create' && (contactFirstName.trim() !== '' || contactLastName.trim() !== '');

        transform((current) => {
            const { contact, ...withoutContact } = current;

            return {
                ...withoutContact,
                ...(hasContact ? { contact } : {}),
                tags: current.tags
                    .split(',')
                    .map((tag: string) => tag.trim())
                    .filter(Boolean),
            };
        });

        if (mode === 'create') {
            post(action);
        } else {
            put(action);
        }
    };

    return (
        <form onSubmit={submit} className="flex flex-col gap-8" noValidate>
            <section className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <Field label="Account name" error={errors.name} required>
                    {(control) => (
                        <input
                            {...control}
                            className={controlClass}
                            value={data.name}
                            onChange={(event) => setData('name', event.target.value)}
                        />
                    )}
                </Field>

                <Field label="Domain" error={errors.domain} hint="e.g. acme.com">
                    {(control) => (
                        <input
                            {...control}
                            className={controlClass}
                            value={data.domain}
                            onChange={(event) => setData('domain', event.target.value)}
                        />
                    )}
                </Field>

                <Field label="Industry" error={errors.industry}>
                    {(control) => (
                        <input
                            {...control}
                            className={controlClass}
                            value={data.industry}
                            onChange={(event) => setData('industry', event.target.value)}
                        />
                    )}
                </Field>

                <Field label="Phone" error={errors.phone}>
                    {(control) => (
                        <input
                            {...control}
                            className={controlClass}
                            value={data.phone}
                            onChange={(event) => setData('phone', event.target.value)}
                        />
                    )}
                </Field>

                <Field label="Status" error={errors.status} required>
                    {(control) => (
                        <select
                            {...control}
                            className={controlClass}
                            value={data.status}
                            onChange={(event) => setData('status', event.target.value)}
                        >
                            <option value="prospect">Prospect</option>
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    )}
                </Field>

                <Field label="Credit terms" error={errors.credit_terms} required>
                    {(control) => (
                        <select
                            {...control}
                            className={controlClass}
                            value={data.credit_terms}
                            onChange={(event) => setData('credit_terms', event.target.value as CreditTerms)}
                        >
                            <option value="net_15">Net 15</option>
                            <option value="net_30">Net 30</option>
                            <option value="net_60">Net 60</option>
                            <option value="prepaid">Prepaid</option>
                        </select>
                    )}
                </Field>

                <Field label="Owner" error={errors.owner_user_id} hint="Defaults to you if left unassigned.">
                    {(control) => (
                        <select
                            {...control}
                            className={controlClass}
                            value={data.owner_user_id}
                            onChange={(event) => setData('owner_user_id', event.target.value)}
                        >
                            <option value="">Unassigned</option>
                            {owners.map((owner) => (
                                <option key={owner.id} value={owner.id}>
                                    {owner.name}
                                </option>
                            ))}
                        </select>
                    )}
                </Field>

                <Field label="Source" error={errors.source}>
                    {(control) => (
                        <input
                            {...control}
                            className={controlClass}
                            value={data.source}
                            onChange={(event) => setData('source', event.target.value)}
                        />
                    )}
                </Field>

                <Field label="Tags" error={errors.tags} hint="Comma-separated">
                    {(control) => (
                        <input
                            {...control}
                            className={controlClass}
                            value={data.tags}
                            onChange={(event) => setData('tags', event.target.value)}
                        />
                    )}
                </Field>
            </section>

            <section className="grid grid-cols-1 gap-6 sm:grid-cols-2">
                <AddressFields
                    legend="Billing address"
                    value={data.billing_address}
                    onChange={(value) => setData('billing_address', value)}
                    errors={errors}
                    prefix="billing_address"
                />
                <AddressFields
                    legend="Shipping address"
                    value={data.shipping_address}
                    onChange={(value) => setData('shipping_address', value)}
                    errors={errors}
                    prefix="shipping_address"
                />
            </section>

            {mode === 'create' && (
                <section className="flex flex-col gap-4 rounded-lg border border-border bg-surface p-4">
                    <h2 className="text-sm font-medium text-text">Primary contact (optional)</h2>
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <Field label="First name" error={errors['contact.first_name']}>
                            {(control) => (
                                <input
                                    {...control}
                                    className={controlClass}
                                    value={data.contact.first_name}
                                    onChange={(event) => setData('contact', { ...data.contact, first_name: event.target.value })}
                                />
                            )}
                        </Field>
                        <Field label="Last name" error={errors['contact.last_name']}>
                            {(control) => (
                                <input
                                    {...control}
                                    className={controlClass}
                                    value={data.contact.last_name}
                                    onChange={(event) => setData('contact', { ...data.contact, last_name: event.target.value })}
                                />
                            )}
                        </Field>
                        <Field label="Email" error={errors['contact.email']}>
                            {(control) => (
                                <input
                                    {...control}
                                    type="email"
                                    className={controlClass}
                                    value={data.contact.email}
                                    onChange={(event) => setData('contact', { ...data.contact, email: event.target.value })}
                                />
                            )}
                        </Field>
                        <Field label="Phone" error={errors['contact.phone']}>
                            {(control) => (
                                <input
                                    {...control}
                                    className={controlClass}
                                    value={data.contact.phone}
                                    onChange={(event) => setData('contact', { ...data.contact, phone: event.target.value })}
                                />
                            )}
                        </Field>
                        <Field label="Title" error={errors['contact.title']}>
                            {(control) => (
                                <input
                                    {...control}
                                    className={controlClass}
                                    value={data.contact.title}
                                    onChange={(event) => setData('contact', { ...data.contact, title: event.target.value })}
                                />
                            )}
                        </Field>
                    </div>

                    <DuplicateEmailNotice
                        field="contact.email"
                        confirmed={confirmDuplicate}
                        onConfirmedChange={(confirmed) => {
                            setConfirmDuplicate(confirmed);
                            setData('confirm_duplicate_email', confirmed);
                        }}
                    />
                </section>
            )}

            <div className="flex justify-end gap-2">
                <Button type="submit" variant="primary" disabled={processing}>
                    {mode === 'create' ? 'Create account' : 'Save changes'}
                </Button>
            </div>
        </form>
    );
}

interface AddressFieldsProps {
    legend: string;
    value: { line1: string; city: string; state: string; postal_code: string; country: string };
    onChange: (value: { line1: string; city: string; state: string; postal_code: string; country: string }) => void;
    errors: Partial<Record<string, string>>;
    prefix: 'billing_address' | 'shipping_address';
}

function AddressFields({ legend, value, onChange, errors, prefix }: AddressFieldsProps) {
    return (
        <fieldset className="flex flex-col gap-3">
            <legend className="text-sm font-medium text-text">{legend}</legend>
            <Field label="Address line" error={errors[`${prefix}.line1`]}>
                {(control) => (
                    <input {...control} className={controlClass} value={value.line1} onChange={(event) => onChange({ ...value, line1: event.target.value })} />
                )}
            </Field>
            <div className="grid grid-cols-2 gap-3">
                <Field label="City" error={errors[`${prefix}.city`]}>
                    {(control) => (
                        <input {...control} className={controlClass} value={value.city} onChange={(event) => onChange({ ...value, city: event.target.value })} />
                    )}
                </Field>
                <Field label="State" error={errors[`${prefix}.state`]}>
                    {(control) => (
                        <input {...control} className={controlClass} value={value.state} onChange={(event) => onChange({ ...value, state: event.target.value })} />
                    )}
                </Field>
                <Field label="Postal code" error={errors[`${prefix}.postal_code`]}>
                    {(control) => (
                        <input
                            {...control}
                            className={controlClass}
                            value={value.postal_code}
                            onChange={(event) => onChange({ ...value, postal_code: event.target.value })}
                        />
                    )}
                </Field>
                <Field label="Country" error={errors[`${prefix}.country`]} hint="ISO 2-letter">
                    {(control) => (
                        <input
                            {...control}
                            className={controlClass}
                            maxLength={2}
                            value={value.country}
                            onChange={(event) => onChange({ ...value, country: event.target.value.toUpperCase() })}
                        />
                    )}
                </Field>
            </div>
        </fieldset>
    );
}
