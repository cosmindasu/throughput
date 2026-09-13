import { Head, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { ButtonLink } from '@/Components/Button';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import AppLayout from '@/Layouts/AppLayout';
import type { SettingsIndexPageProps, SettingsSectionPermissions } from '@/types/generated';

interface SettingsSection {
    key: keyof SettingsSectionPermissions;
    title: string;
    description: string;
    href: (workspaceSlug: string) => string;
    /** Faza 5 (plan §7.4) — badge, fără acțiune: niciun link mort. */
    comingSoon?: boolean;
}

/**
 * Ordinea reflectă plan §7.4/§8: Members, Billing, API Tokens sunt shell — pagina
 * propriu-zisă e Faza 5. Pipeline duce la pachetul care construiește `/{w}/pipeline`
 * în aceeași fază. Preferences e construită acum (specs.md §15.6).
 */
const SECTIONS: SettingsSection[] = [
    {
        key: 'members',
        title: 'Members',
        description: 'Invite teammates and manage their roles.',
        href: (w) => `/${w}/settings/members`,
        comingSoon: true,
    },
    {
        key: 'billing',
        title: 'Billing & Subscription',
        description: 'Manage the Throughput subscription and payment method.',
        href: (w) => `/${w}/settings/billing`,
        comingSoon: true,
    },
    {
        key: 'apiTokens',
        title: 'API Tokens',
        description: 'Create and revoke tokens for the public API.',
        href: (w) => `/${w}/settings/api-tokens`,
        comingSoon: true,
    },
    {
        key: 'pipeline',
        title: 'Pipeline',
        description: 'Configure the pipeline stages used by the deals board.',
        href: (w) => `/${w}/pipeline`,
    },
    {
        key: 'preferences',
        title: 'Preferences',
        description: 'Theme and other personal preferences.',
        href: (w) => `/${w}/settings/preferences`,
    },
];

/**
 * Settings shell (plan §7.4) — secțiunile vizibile diferă pe rol, calculat
 * server-side prin `can` (§7.3, FR-RBAC-01): o secțiune fără drept LIPSEȘTE din
 * listă, nu e randată dezactivată.
 */
export default function SettingsIndex() {
    const { workspace, can } = usePage<SettingsIndexPageProps>().props;

    return (
        <>
            <Head title="Settings" />

            <div className="flex flex-col gap-6">
                <PageHeader title="Settings" />

                <div className="grid gap-4 sm:grid-cols-2">
                    {SECTIONS.filter((section) => can[section.key]).map((section) => (
                        <div key={section.key} className="flex flex-col gap-3 rounded-lg border border-border bg-surface p-4">
                            <div className="flex items-start justify-between gap-2">
                                <h2 className="text-sm font-medium text-text">{section.title}</h2>
                                {section.comingSoon && <StatusBadge tone="neutral">Coming in a later phase</StatusBadge>}
                            </div>

                            <p className="text-sm text-text-2">{section.description}</p>

                            {!section.comingSoon && workspace && (
                                <div>
                                    <ButtonLink href={section.href(workspace.slug)} variant="secondary" prefetch>
                                        Open
                                    </ButtonLink>
                                </div>
                            )}
                        </div>
                    ))}
                </div>
            </div>
        </>
    );
}

SettingsIndex.layout = (page: ReactNode) => <AppLayout>{page}</AppLayout>;
