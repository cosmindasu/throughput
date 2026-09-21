import { Head, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import AccountForm from '@/Components/Accounts/AccountForm';
import PageHeader from '@/Components/PageHeader';
import AppLayout from '@/Layouts/AppLayout';
import type { AccountsFormPageProps } from '@/types/generated';

/**
 * Accounts/Create — US-CRM-01: contul + contactul principal opțional, în același
 * formular și aceeași tranzacție (vezi `AccountController::store()`).
 */
export default function Create() {
    const { t } = useTranslation('accounts');
    const { owners, prefill, workspace } = usePage<AccountsFormPageProps>().props;
    const base = workspace ? `/${workspace.slug}` : '';

    return (
        <>
            <Head title={t('create.title')} />

            <div className="flex max-w-3xl flex-col gap-6">
                <PageHeader title={t('create.title')} />
                <AccountForm mode="create" owners={owners} prefillName={prefill?.name} action={`${base}/accounts`} />
            </div>
        </>
    );
}

Create.layout = (page: ReactNode) => <AppLayout>{page}</AppLayout>;
