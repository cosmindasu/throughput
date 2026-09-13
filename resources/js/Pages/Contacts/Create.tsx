import { Head, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import ContactForm from '@/Components/Contacts/ContactForm';
import PageHeader from '@/Components/PageHeader';
import AppLayout from '@/Layouts/AppLayout';
import type { ContactsCreatePageProps } from '@/types/generated';

/**
 * FR-CRM-02, US-CRM-01 — nou contact, opțional precompletat cu contul din care s-a
 * dat click pe „Add contact" (`?account={id}`, citit server-side în controller).
 */
export default function ContactsCreate() {
    const { account, workspace } = usePage<ContactsCreatePageProps>().props;

    return (
        <>
            <Head title="New contact" />

            <div className="flex max-w-xl flex-col gap-6">
                <PageHeader
                    title="New contact"
                    description={account ? `For ${account.name}.` : 'A person you work with — with or without a company yet.'}
                />

                <ContactForm
                    submitLabel="Create contact"
                    action={workspace ? `/${workspace.slug}/contacts` : '#'}
                    method="post"
                    initialAccount={account}
                />
            </div>
        </>
    );
}

ContactsCreate.layout = (page: ReactNode) => <AppLayout>{page}</AppLayout>;
