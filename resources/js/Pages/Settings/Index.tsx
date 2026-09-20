import { Head, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { ButtonLink } from '@/Components/Button';
import PageHeader from '@/Components/PageHeader';
import AppLayout from '@/Layouts/AppLayout';
import type { SettingsIndexPageProps, SettingsSectionPermissions } from '@/types/generated';

interface SettingsSection {
    key: keyof SettingsSectionPermissions;
    title: string;
    description: string;
    href: (workspaceSlug: string) => string;
}

/**
 * Ordinea reflectă plan §7.4/§8. **Nicio secțiune nu mai e shell**: ultima, API Tokens, a
 * primit ecranul ei în valul 2 al Fazei 5, odată cu Export data și Webhook health. Odată cu
 * ea a căzut și câmpul `comingSoon` plus insigna lui — un mecanism fără niciun utilizator,
 * într-un proiect în care faza următoare e prezentarea, nu module noi. Dacă reapare vreodată
 * o secțiune amânată, se rescrie; ce nu se face e să rămână un `if` mort care sugerează că
 * ecranele încă lipsesc.
 */
const SECTIONS: SettingsSection[] = [
    {
        key: 'members',
        title: 'Members',
        description: 'See who has access, and deactivate someone who left.',
        href: (w) => `/${w}/settings/members`,
    },
    {
        key: 'billing',
        title: 'Billing & Subscription',
        description: 'Manage the Throughput subscription and payment method.',
        href: (w) => `/${w}/settings/billing`,
    },
    {
        key: 'apiTokens',
        title: 'API Tokens',
        description: 'Create and revoke tokens for the public API.',
        href: (w) => `/${w}/settings/api-tokens`,
    },
    {
        key: 'carrierSettings',
        title: 'Carrier settings',
        description: 'Choose the shipping carrier this workspace uses, and store its sandbox credentials.',
        href: (w) => `/${w}/settings/shipping`,
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
    {
        key: 'sentEmails',
        title: 'Sent Emails',
        description: 'Every transactional email the public demo tried to send — delivered or intercepted (§22.3).',
        href: (w) => `/${w}/settings/sent-emails`,
    },
    {
        key: 'dataExports',
        title: 'Export data',
        description: 'Request a complete, machine-readable copy of everything this workspace holds.',
        href: (w) => `/${w}/settings/data-export`,
    },
    {
        key: 'webhooks',
        title: 'Webhook health',
        description: 'See what Stripe has sent this deployment, and what happened to it.',
        href: (w) => `/${w}/settings/webhooks`,
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
                            <h2 className="text-sm font-medium text-text">{section.title}</h2>

                            <p className="text-sm text-text-2">{section.description}</p>

                            {workspace && (
                                <div>
                                    {/* SC 2.4.4 / 4.1.2 — „Open" identic pe fiecare card ar da N linkuri cu
                                        același nume accesibil, fără context (.ai/rules/frontend.md, „Un nume
                                        accesibil repetat pe fiecare rând..."); `aria-label` spune UNDE duce. */}
                                    <ButtonLink
                                        href={section.href(workspace.slug)}
                                        variant="secondary"
                                        prefetch
                                        aria-label={`Open ${section.title}`}
                                    >
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
