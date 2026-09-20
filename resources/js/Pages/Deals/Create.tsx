import { Head, router, useForm, usePage } from '@inertiajs/react';
import type { FormEvent, ReactNode } from 'react';
import AccountCombobox from '@/Components/AccountCombobox';
import Button, { ButtonLink } from '@/Components/Button';
import Field, { controlClass } from '@/Components/Form/Field';
import PageHeader from '@/Components/PageHeader';
import AppLayout from '@/Layouts/AppLayout';
import type { DealsCreatePageProps } from '@/types/generated';

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
 * US-DEAL-01 — precompletat din `?account=` când link-ul „New deal" vine din pagina unui
 * cont (`Accounts/Show.tsx`), dar nu mai e IMPUS: contul e ales prin `AccountCombobox`
 * (același tipar reutilizat din `Contacts/ContactForm.tsx`, P2-001), obligatoriu la
 * submit. Fără `?account=`, câmpul pornește gol, nu 404 (§9 task).
 *
 * Etapa nu se alege aici — `CreateDealAction` pune deal-ul pe prima etapă a
 * pipeline-ului implicit.
 */
export default function Create() {
    const { account, contacts, owners, can, workspace } = usePage<DealsCreatePageProps>().props;
    const workspaceSlug = workspace?.slug ?? '';
    const basePath = `/${workspaceSlug}/deals/create`;

    const { data, setData, post, processing, errors } = useForm<DealFormData>({
        account_id: account?.id ?? '',
        title: '',
        value: '',
        expected_close_date: '',
        primary_contact_id: '',
        owner_user_id: '',
    });

    // Contactul principal depinde de cont (§9 task): la schimbarea contului, contactul
    // ales se golește imediat (nu poate mai fi valid pentru contul nou) și pagina cere o
    // reîncărcare parțială a acestei rute — `DealController::create()` recalculează
    // `contacts` pentru noul `?account=`, exact mecanismul deja folosit de
    // `useListFilters` pentru filtre de listă (`router.get` + `preserveState`).
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

        post(`/${workspaceSlug}/deals`);
    };

    return (
        <>
            <Head title="New deal" />

            <div className="flex flex-col gap-6">
                <PageHeader title="New deal" description={account ? `For ${account.name}` : undefined} />

                <form onSubmit={submit} className="flex max-w-xl flex-col gap-4 rounded-lg border border-border bg-surface p-4">
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

                    <Field label="Title" error={errors.title} required>
                        {(control) => (
                            <input
                                {...control}
                                className={controlClass}
                                value={data.title}
                                onChange={(event) => setData('title', event.target.value)}
                            />
                        )}
                    </Field>

                    <Field label="Value" error={errors.value} hint="Leave blank until qualified.">
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

                    <Field label="Expected close date" error={errors.expected_close_date}>
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

                    <Field label="Primary contact" error={errors.primary_contact_id}>
                        {(control) => (
                            <select
                                {...control}
                                className={controlClass}
                                value={data.primary_contact_id}
                                onChange={(event) => setData('primary_contact_id', event.target.value)}
                            >
                                <option value="">None</option>
                                {contacts.map((contact) => (
                                    <option key={contact.id} value={contact.id}>
                                        {contact.name}
                                    </option>
                                ))}
                            </select>
                        )}
                    </Field>

                    {can.changeOwner && (
                        <Field label="Owner" error={errors.owner_user_id} hint="Leave as “Me” to keep yourself as owner.">
                            {(control) => (
                                <select
                                    {...control}
                                    className={controlClass}
                                    value={data.owner_user_id}
                                    onChange={(event) => setData('owner_user_id', event.target.value)}
                                >
                                    <option value="">Me</option>
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
                        <Button type="submit" variant="primary" pending={processing} pendingLabel="Creating…">
                            Create deal
                        </Button>
                        <ButtonLink href={account ? `/${workspaceSlug}/accounts/${account.id}` : `/${workspaceSlug}/deals`}>
                            Cancel
                        </ButtonLink>
                    </div>
                </form>
            </div>
        </>
    );
}

Create.layout = (page: ReactNode) => <AppLayout>{page}</AppLayout>;
