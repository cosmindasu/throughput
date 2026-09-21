import { Head, router, useForm, usePage } from '@inertiajs/react';
import type { FormEvent, ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import AccountCombobox from '@/Components/AccountCombobox';
import Button, { ButtonLink } from '@/Components/Button';
import Field, { controlClass } from '@/Components/Form/Field';
import PageHeader from '@/Components/PageHeader';
import AppLayout from '@/Layouts/AppLayout';
import type { DealsEditPageProps } from '@/types/generated';

interface DealFormData {
    account_id: string;
    title: string;
    value: string;
    expected_close_date: string;
    primary_contact_id: string;
    owner_user_id: string;
    [key: string]: string;
}

/**
 * Cont (via `AccountCombobox`, aceeași componentă ca `Create.tsx`/`ContactForm.tsx`),
 * titlu, valoare, dată estimată, contact principal, owner (doar cu `can.changeOwner`).
 * ETAPA NU se schimbă din acest formular (§9 task) — doar din `Deals/Show` sau
 * `Deals/Kanban`, prin `MoveStageMenu`/drag & drop.
 *
 * Contul a devenit editabil (cerință explicită, care înlocuiește restricția anterioară —
 * vezi `App\Http\Requests\Deals\UpdateDealRequest`): mutarea pe alt cont nu schimbă etapa,
 * istoricul `deal_stage_events` sau owner-ul — validarea și scrierea reală sunt server-side
 * (`UpdateDealRequest` + `DealController::update()`).
 *
 * Code review P2-002 — `account_id`/eticheta combobox-ului pornesc din propul `account`
 * (rezolvat de server, nu din `deal.account`): `deal.account` rămâne mereu contul SALVAT,
 * neschimbat până la submit, deci la reîncărcare cu `?account=B` în URL (P2-002) sau după
 * un refresh, `deal.account` (A) și `contacts` (pentru B) ar diverge dacă formularul ar
 * porni tot din `deal.account`. O singură sursă de adevăr: server-ul rezolvă `account` și
 * `contacts` din ACELAȘI cont, în `DealController::edit()`.
 */
export default function Edit() {
    const { t } = useTranslation('deals');
    const { deal, account, contacts, owners, can, workspace } = usePage<DealsEditPageProps>().props;
    const workspaceSlug = workspace?.slug ?? '';
    const basePath = `/${workspaceSlug}/deals/${deal.id}/edit`;
    const primaryContact = deal.primaryContact;

    const { data, setData, put, processing, errors } = useForm<DealFormData>({
        account_id: account?.id ?? '',
        title: deal.title,
        value: deal.value !== null ? String(deal.value) : '',
        expected_close_date: deal.expectedCloseDate ?? '',
        primary_contact_id: deal.primaryContact?.id ?? '',
        owner_user_id: deal.owner.id,
    });

    // Contactul principal depinde de cont (§9 task): la schimbarea contului, contactul
    // ales se golește imediat, iar `DealController::edit()` recalculează `contacts`
    // pentru noul cont, printr-o reîncărcare parțială a aceleiași pagini — deal-ul
    // propriu-zis (etapă, istoric, owner) rămâne neschimbat până la submit.
    //
    // Code review P2-001 — `account` se trimite ÎNTOTDEAUNA, chiar gol (`''`) la „Clear":
    // omiterea cheii la golire făcea `$request->has('account')` fals pe server, care cădea
    // pe contul VECHI al deal-ului — „Clear" arăta câmpul gol, dar oferea tot contactele
    // contului anterior.
    const handleAccountChange = (accountId: string | null) => {
        setData((current) => ({
            ...current,
            account_id: accountId ?? '',
            primary_contact_id: '',
        }));

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

        put(`/${workspaceSlug}/deals/${deal.id}`);
    };

    return (
        <>
            <Head title={t('edit.title', { title: deal.title })} />

            <div className="flex flex-col gap-6">
                <PageHeader title={t('edit.title', { title: deal.title })} description={deal.account.name} />

                <form onSubmit={submit} className="flex max-w-xl flex-col gap-4 rounded-lg border border-border bg-surface p-4">
                    <Field label={t('edit.fields.account.label')} error={errors.account_id} required>
                        {(control) => (
                            <AccountCombobox
                                {...control}
                                value={data.account_id.trim() === '' ? null : data.account_id}
                                initialLabel={account?.name ?? null}
                                onChange={handleAccountChange}
                            />
                        )}
                    </Field>

                    <Field label={t('edit.fields.title.label')} error={errors.title} required>
                        {(control) => (
                            <input
                                {...control}
                                className={controlClass}
                                value={data.title}
                                onChange={(event) => setData('title', event.target.value)}
                            />
                        )}
                    </Field>

                    <Field label={t('edit.fields.value.label')} error={errors.value}>
                        {(control) => (
                            <input
                                {...control}
                                type="number"
                                min="0"
                                step="0.01"
                                className={controlClass}
                                value={data.value}
                                onChange={(event) => setData('value', event.target.value)}
                            />
                        )}
                    </Field>

                    <Field label={t('edit.fields.expectedCloseDate.label')} error={errors.expected_close_date}>
                        {(control) => (
                            <input
                                {...control}
                                type="date"
                                className={controlClass}
                                value={data.expected_close_date}
                                onChange={(event) => setData('expected_close_date', event.target.value)}
                            />
                        )}
                    </Field>

                    <Field label={t('edit.fields.primaryContact.label')} error={errors.primary_contact_id}>
                        {(control) => (
                            <select
                                {...control}
                                className={controlClass}
                                value={data.primary_contact_id}
                                onChange={(event) => setData('primary_contact_id', event.target.value)}
                            >
                                <option value="">{t('edit.fields.primaryContact.none')}</option>
                                {/*
                                    §20.5 — un contact principal anonimizat NU apare în `contacts`
                                    (opțiunile normale, `contactsForAccount()`), dar legătura încă
                                    există pe deal: o opțiune separată, informativă, arată asta în
                                    loc să lase select-ul fără nicio valoare selectată (care ar fi
                                    citit, la salvare, ca „niciun contact"). Dispare singură dacă
                                    utilizatorul alege alt contact sau schimbă contul — condiția e
                                    `data.primary_contact_id`, nu un steag separat.
                                */}
                                {primaryContact?.isAnonymized && data.primary_contact_id === primaryContact.id && (
                                    <option value={primaryContact.id}>{t('edit.fields.primaryContact.anonymized')}</option>
                                )}
                                {contacts.map((contact) => (
                                    <option key={contact.id} value={contact.id}>
                                        {contact.name}
                                    </option>
                                ))}
                            </select>
                        )}
                    </Field>

                    {can.changeOwner && (
                        <Field label={t('edit.fields.owner.label')} error={errors.owner_user_id}>
                            {(control) => (
                                <select
                                    {...control}
                                    className={controlClass}
                                    value={data.owner_user_id}
                                    onChange={(event) => setData('owner_user_id', event.target.value)}
                                >
                                    {owners.map((owner) => (
                                        <option key={owner.id} value={owner.id}>
                                            {owner.name}
                                        </option>
                                    ))}
                                </select>
                            )}
                        </Field>
                    )}

                    <div className="flex items-center gap-2">
                        <Button type="submit" variant="primary" pending={processing} pendingLabel={t('edit.actions.submitPending')}>
                            {t('edit.actions.submit')}
                        </Button>
                        <ButtonLink href={`/${workspaceSlug}/deals/${deal.id}`}>{t('edit.actions.cancel')}</ButtonLink>
                    </div>
                </form>
            </div>
        </>
    );
}

Edit.layout = (page: ReactNode) => <AppLayout>{page}</AppLayout>;
