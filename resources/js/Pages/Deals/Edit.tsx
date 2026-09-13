import { Head, useForm, usePage } from '@inertiajs/react';
import type { FormEvent, ReactNode } from 'react';
import Button, { ButtonLink } from '@/Components/Button';
import Field, { controlClass } from '@/Components/Form/Field';
import PageHeader from '@/Components/PageHeader';
import AppLayout from '@/Layouts/AppLayout';
import type { DealsEditPageProps } from '@/types/generated';

interface DealFormData {
    title: string;
    value: string;
    expected_close_date: string;
    primary_contact_id: string;
    owner_user_id: string;
    [key: string]: string;
}

/**
 * Titlu, valoare, dată estimată, contact principal, owner (doar cu `can.changeOwner`).
 * ETAPA NU se schimbă din acest formular (§9 task) — doar din `Deals/Show` sau
 * `Deals/Kanban`, prin `MoveStageMenu`/drag & drop.
 *
 * Contul nu e editabil aici — vezi comentariul din `App\Http\Requests\Deals\UpdateDealRequest`
 * pentru motiv (Accounts, cu propriul selector/căutare, e alt pachet).
 */
export default function Edit() {
    const { deal, contacts, owners, can, workspace } = usePage<DealsEditPageProps>().props;
    const workspaceSlug = workspace?.slug ?? '';

    const { data, setData, put, processing, errors } = useForm<DealFormData>({
        title: deal.title,
        value: deal.value !== null ? String(deal.value) : '',
        expected_close_date: deal.expectedCloseDate ?? '',
        primary_contact_id: deal.primaryContact?.id ?? '',
        owner_user_id: deal.owner.id,
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        put(`/${workspaceSlug}/deals/${deal.id}`);
    };

    return (
        <>
            <Head title={`Edit ${deal.title}`} />

            <div className="flex flex-col gap-6">
                <PageHeader title={`Edit ${deal.title}`} description={deal.account.name} />

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

                    <Field label="Value" error={errors.value}>
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
                        <Field label="Owner" error={errors.owner_user_id}>
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
                        <Button type="submit" variant="primary" disabled={processing}>
                            Save changes
                        </Button>
                        <ButtonLink href={`/${workspaceSlug}/deals/${deal.id}`}>Cancel</ButtonLink>
                    </div>
                </form>
            </div>
        </>
    );
}

Edit.layout = (page: ReactNode) => <AppLayout>{page}</AppLayout>;
