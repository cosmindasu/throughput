import { Head, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import ContactForm from '@/Components/Contacts/ContactForm';
import PageHeader from '@/Components/PageHeader';
import AppLayout from '@/Layouts/AppLayout';
import type { ContactsEditPageProps } from '@/types/generated';

/**
 * FR-CRM-02 — editare de contact, inclusiv mutarea între conturi (câmpul „Account ID").
 * Accesul e deja verificat server-side (`ContactPolicy::update`) înainte ca pagina să
 * se randeze — un Agent pe contactul altcuiva primește 403, nu formularul.
 */
export default function ContactsEdit() {
    const { t } = useTranslation('contacts');
    const { contact, workspace } = usePage<ContactsEditPageProps>().props;

    return (
        <>
            <Head title={t('edit.title', { name: contact.fullName })} />

            <div className="flex max-w-xl flex-col gap-6">
                <PageHeader title={t('edit.title', { name: contact.fullName })} />

                <ContactForm
                    contact={contact}
                    submitLabel={t('edit.submit')}
                    action={workspace ? `/${workspace.slug}/contacts/${contact.id}` : '#'}
                    method="put"
                />
            </div>
        </>
    );
}

ContactsEdit.layout = (page: ReactNode) => <AppLayout>{page}</AppLayout>;
