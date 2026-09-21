import { Head, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import ContactForm from '@/Components/Contacts/ContactForm';
import PageHeader from '@/Components/PageHeader';
import AppLayout from '@/Layouts/AppLayout';
import type { ContactsCreatePageProps } from '@/types/generated';

/**
 * FR-CRM-02, US-CRM-01 — nou contact, opțional precompletat cu contul din care s-a
 * dat click pe „Add contact" (`?account={id}`, citit server-side în controller).
 */
export default function ContactsCreate() {
    const { t } = useTranslation('contacts');
    const { account, workspace } = usePage<ContactsCreatePageProps>().props;

    return (
        <>
            <Head title={t('create.title')} />

            <div className="flex max-w-xl flex-col gap-6">
                <PageHeader
                    title={t('create.title')}
                    description={account ? t('create.descriptionForAccount', { name: account.name }) : t('create.descriptionNoAccount')}
                />

                <ContactForm
                    submitLabel={t('create.submit')}
                    action={workspace ? `/${workspace.slug}/contacts` : '#'}
                    method="post"
                    initialAccount={account}
                />
            </div>
        </>
    );
}

ContactsCreate.layout = (page: ReactNode) => <AppLayout>{page}</AppLayout>;
