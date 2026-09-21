import { useForm } from '@inertiajs/react';
import { useId, type FormEvent } from 'react';
import { useTranslation } from 'react-i18next';
import AccountCombobox from '@/Components/AccountCombobox';
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
 * `account_id` se alege prin `AccountCombobox` (code review P2-001) — Marlin are
 * ~4.000 de conturi, un „Account ID" text liber în care se lipește un ULID era
 * inutilizabil într-un demo public. Legarea/mutarea contactului pe un alt cont se
 * face alegând din listă, nu lipind id-ul.
 */
export default function ContactForm({ contact, initialAccount = null, submitLabel, action, method }: ContactFormProps) {
    const { t } = useTranslation('contacts');
    const primaryCheckboxId = useId();
    const primaryErrorId = `${primaryCheckboxId}-error`;
    const primaryHintId = `${primaryCheckboxId}-hint`;
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

    // Eticheta inițială a `AccountCombobox` — numele contului deja legat la editare, sau
    // cel precompletat din `?account=` la creare (US-CRM-01).
    const initialAccountName = contact?.account?.name ?? initialAccount?.name ?? null;

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();

        // Butonul de submit e `aria-disabled`, nu `disabled` nativ (`Button`, prop
        // `pending`) — al doilea submit se oprește AICI, nu de browser.
        if (processing) {
            return;
        }

        if (method === 'put') {
            put(action);
        } else {
            post(action);
        }
    };

    return (
        <form onSubmit={submit} className="flex flex-col gap-4" noValidate>
            <Field label={t('form.firstName.label')} required error={errors.first_name}>
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

            <Field label={t('form.lastName.label')} required error={errors.last_name}>
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

            <Field label={t('form.jobTitle.label')} error={errors.title}>
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
                <Field label={t('form.email.label')} error={errors.email}>
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

            <Field label={t('form.phone.label')} error={errors.phone}>
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

            <Field
                label={t('form.account.label')}
                error={errors.account_id}
                hint={t('form.account.hint')}
            >
                {(control) => (
                    <AccountCombobox
                        {...control}
                        value={data.account_id.trim() === '' ? null : data.account_id}
                        initialLabel={initialAccountName}
                        onChange={(accountId) =>
                            setData((current) => ({
                                ...current,
                                account_id: accountId ?? '',
                                // P2-003 (code review) — un contact fără cont nu poate fi
                                // primary: golirea contului debifează, altfel checkbox-ul
                                // rămâne bifat și dezactivat, fără cale de reparare.
                                is_primary: accountId === null ? false : current.is_primary,
                            }))
                        }
                    />
                )}
            </Field>

            <div className="flex flex-col gap-1">
                <label htmlFor={primaryCheckboxId} className="flex items-center gap-2 text-sm text-text">
                    {/* `disabled` rămâne corect aici: bifa se blochează pentru că utilizatorul
                        a golit câmpul „Account" de deasupra, deci focusul e pe ACEL control, nu
                        pe bifă — nu e cazul „butonul apăsat se dezactivează sub focus".
                        Ce lipsea (SC 3.3.2 Labels or Instructions) era MOTIVUL: un control
                        dezactivat iese din ordinea de Tab și dispare din lista de câmpuri a
                        cititorului de ecran, fără nimic care să spună de ce. Explicația e
                        `sr-only` — vizual, câmpul gol de deasupra o spune deja. */}
                    <input
                        id={primaryCheckboxId}
                        type="checkbox"
                        checked={data.is_primary}
                        disabled={data.account_id.trim() === ''}
                        aria-describedby={
                            [
                                errors.is_primary ? primaryErrorId : null,
                                data.account_id.trim() === '' ? primaryHintId : null,
                            ]
                                .filter(Boolean)
                                .join(' ') || undefined
                        }
                        aria-invalid={errors.is_primary ? true : undefined}
                        onChange={(event) => setData('is_primary', event.target.checked)}
                        className="h-4 w-4 accent-[var(--accent-fill)] disabled:cursor-not-allowed disabled:opacity-60"
                    />
                    {t('form.primary.label')}
                </label>
                <p id={primaryHintId} className="sr-only">
                    {t('form.primary.hint')}
                </p>
                {errors.is_primary && (
                    <p id={primaryErrorId} role="alert" className="text-xs text-danger">
                        {errors.is_primary}
                    </p>
                )}
            </div>

            <label className="flex items-center gap-2 text-sm text-text">
                <input
                    type="checkbox"
                    checked={data.opt_out}
                    onChange={(event) => setData('opt_out', event.target.checked)}
                    className="h-4 w-4 accent-[var(--accent-fill)]"
                />
                {t('form.optOut')}
            </label>

            <div className="flex justify-end gap-2">
                <Button variant="primary" type="submit" pending={processing} pendingLabel={t('form.submitPending')}>
                    {submitLabel}
                </Button>
            </div>
        </form>
    );
}
