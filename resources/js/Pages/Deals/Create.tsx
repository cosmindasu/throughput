import { Head, useForm, usePage } from '@inertiajs/react';
import type { FormEvent, ReactNode } from 'react';
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
 * US-DEAL-01 — precompletat din `?account=` (§9 task). Contul e fix (deal-urile se
 * creează DIN pagina unui cont); etapa nu se alege aici — `CreateDealAction` pune deal-ul
 * pe prima etapă a pipeline-ului implicit.
 */
export default function Create() {
    const { account, contacts, owners, can, workspace } = usePage<DealsCreatePageProps>().props;
    const workspaceSlug = workspace?.slug ?? '';

    const { data, setData, post, processing, errors } = useForm<DealFormData>({
        account_id: account.id,
        title: '',
        value: '',
        expected_close_date: '',
        primary_contact_id: '',
        owner_user_id: '',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        post(`/${workspaceSlug}/deals`);
    };

    return (
        <>
            <Head title="New deal" />

            <div className="flex flex-col gap-6">
                <PageHeader title="New deal" description={`For ${account.name}`} />

                <form onSubmit={submit} className="flex max-w-xl flex-col gap-4 rounded-lg border border-border bg-surface p-4">
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
                        <Button type="submit" variant="primary" disabled={processing}>
                            Create deal
                        </Button>
                        <ButtonLink href={`/${workspaceSlug}/accounts/${account.id}`}>Cancel</ButtonLink>
                    </div>
                </form>
            </div>
        </>
    );
}

Create.layout = (page: ReactNode) => <AppLayout>{page}</AppLayout>;
