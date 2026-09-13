import { Link, useForm, usePage } from '@inertiajs/react';
import { type FormEvent } from 'react';
import Button from '@/Components/Button';
import DuplicateEmailNotice from '@/Components/DuplicateEmailNotice';
import Field, { controlClass } from '@/Components/Form/Field';
import type { Contact, ContactAccountSummary } from '@/types/generated';

interface ContactFormValues {
    account_id: string;
    first_name: string;
    last_name: string;
    email: string;
    phone: string;
    title: string;
    is_primary: boolean;
    opt_out: boolean;
    confirm_duplicate_email: boolean;
    [key: string]: string | boolean;
}

interface ContactFormProps {
    /** Prezent doar la editare — populează formularul cu valorile curente. */
    contact?: Contact;
    /** Precompletare `?account={id}` la creare (US-CRM-01, linkul „Add contact"). */
    initialAccount?: ContactAccountSummary | null;
    submitLabel: string;
    action: string;
    method: 'post' | 'put';
}

/**
 * Formular comun Create/Edit — FR-CRM-02, US-CRM-01.
 *
 * `account_id` e un câmp text (nu un selector de conturi): modulul de Conturi, cu
 * lista/căutarea lui de 4.000-8.000 rânduri, e un pachet separat în lucru în paralel
 * (vezi raportul de livrare) — legarea/mutarea contactului pe un alt cont se face
 * aici lipind ID-ul, iar contul deja legat rămâne un link către pagina lui.
 */
export default function ContactForm({ contact, initialAccount = null, submitLabel, action, method }: ContactFormProps) {
    const { workspace } = usePage().props;
    const { data, setData, post, put, processing, errors } = useForm<ContactFormValues>({
        account_id: contact?.accountId ?? initialAccount?.id ?? '',
        first_name: contact?.firstName ?? '',
        last_name: contact?.lastName ?? '',
        email: contact?.email ?? '',
        phone: contact?.phone ?? '',
        title: contact?.title ?? '',
        is_primary: contact?.isPrimary ?? false,
        opt_out: contact?.optOut ?? false,
        confirm_duplicate_email: false,
    });

    const linkedAccount = contact?.account ?? initialAccount;

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();

        if (method === 'put') {
            put(action);
        } else {
            post(action);
        }
    };

    return (
        <form onSubmit={submit} className="flex flex-col gap-4" noValidate>
            <Field label="First name" required error={errors.first_name}>
                {(control) => (
                    <input
                        {...control}
                        type="text"
                        autoComplete="given-name"
                        value={data.first_name}
                        onChange={(event) => setData('first_name', event.target.value)}
                        className={controlClass}
                    />
                )}
            </Field>

            <Field label="Last name" required error={errors.last_name}>
                {(control) => (
                    <input
                        {...control}
                        type="text"
                        autoComplete="family-name"
                        value={data.last_name}
                        onChange={(event) => setData('last_name', event.target.value)}
                        className={controlClass}
                    />
                )}
            </Field>

            <Field label="Job title" error={errors.title}>
                {(control) => (
                    <input
                        {...control}
                        type="text"
                        value={data.title}
                        onChange={(event) => setData('title', event.target.value)}
                        className={controlClass}
                    />
                )}
            </Field>

            <div className="flex flex-col gap-2">
                <Field label="Email" error={errors.email}>
                    {(control) => (
                        <input
                            {...control}
                            type="email"
                            autoComplete="email"
                            value={data.email}
                            onChange={(event) => setData('email', event.target.value)}
                            className={controlClass}
                        />
                    )}
                </Field>
                <DuplicateEmailNotice
                    field="email"
                    confirmed={data.confirm_duplicate_email}
                    onConfirmedChange={(confirmed) => setData('confirm_duplicate_email', confirmed)}
                />
            </div>

            <Field label="Phone" error={errors.phone}>
                {(control) => (
                    <input
                        {...control}
                        type="tel"
                        autoComplete="tel"
                        value={data.phone}
                        onChange={(event) => setData('phone', event.target.value)}
                        className={controlClass}
                    />
                )}
            </Field>

            <div className="flex flex-col gap-1">
                <Field
                    label="Account ID"
                    error={errors.account_id}
                    hint="Leave empty for a lead without a company yet. Paste an account's ID to link or move this contact."
                >
                    {(control) => (
                        <input
                            {...control}
                            type="text"
                            value={data.account_id}
                            onChange={(event) => setData('account_id', event.target.value)}
                            className={`${controlClass} font-mono`}
                        />
                    )}
                </Field>
                {linkedAccount && workspace && (
                    <p className="text-xs text-text-2">
                        Currently linked to{' '}
                        <Link href={`/${workspace.slug}/accounts/${linkedAccount.id}`} className="underline underline-offset-2">
                            {linkedAccount.name}
                        </Link>
                        .
                    </p>
                )}
            </div>

            <label className="flex items-center gap-2 text-sm text-text">
                <input
                    type="checkbox"
                    checked={data.is_primary}
                    disabled={data.account_id.trim() === ''}
                    onChange={(event) => setData('is_primary', event.target.checked)}
                    className="h-4 w-4 accent-[var(--accent-fill)] disabled:cursor-not-allowed disabled:opacity-60"
                />
                Primary contact for this account
            </label>
            {errors.is_primary && (
                <p role="alert" className="-mt-2 text-xs text-danger">
                    {errors.is_primary}
                </p>
            )}

            <label className="flex items-center gap-2 text-sm text-text">
                <input
                    type="checkbox"
                    checked={data.opt_out}
                    onChange={(event) => setData('opt_out', event.target.checked)}
                    className="h-4 w-4 accent-[var(--accent-fill)]"
                />
                Opted out of marketing communications
            </label>

            <div className="flex justify-end gap-2">
                <Button variant="primary" type="submit" disabled={processing}>
                    {processing ? 'Saving…' : submitLabel}
                </Button>
            </div>
        </form>
    );
}
