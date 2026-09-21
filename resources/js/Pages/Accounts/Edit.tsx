import { Head, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import AccountForm from '@/Components/Accounts/AccountForm';
import PageHeader from '@/Components/PageHeader';
import AppLayout from '@/Layouts/AppLayout';
import type { AccountsFormPageProps } from '@/types/generated';

/**
 * Accounts/Edit — FR-CRM-01. Fără secțiune de contact: contactele au CRUD propriu.
 */
export default function Edit() {
    const { t } = useTranslation('accounts');
    const { account, owners, workspace } = usePage<AccountsFormPageProps>().props;
    const base = workspace ? `/${workspace.slug}` : '';

    if (!account) {
        return null;
    }

    return (
        <>
            <Head title={t('edit.title', { name: account.name })} />

            <div className="flex max-w-3xl flex-col gap-6">
                <PageHeader title={t('edit.title', { name: account.name })} />
                <AccountForm mode="edit" account={account} owners={owners} action={`${base}/accounts/${account.id}`} />
            </div>
        </>
    );
}

Edit.layout = (page: ReactNode) => <AppLayout>{page}</AppLayout>;
