import { Head, usePage } from '@inertiajs/react';
import { useMemo, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import type { TFunction } from 'i18next';
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
 *
 * Fabrică parametrizată, nu tablou de modul (Lot I18N, Val 3, `.ai/rules/frontend.md`):
 * titlul/descrierea fiecărei secțiuni sunt etichete traduse, deci array-ul nu poate mai fi
 * o constantă calculată o singură dată la import — `useMemo(() => buildSections(t), [t])` în
 * componentă, ca la `buildDealColumns`/`buildOrderColumns` din `Deals/Index.tsx`/`Orders/Index.tsx`.
 */
const buildSections = (t: TFunction<'settings'>): SettingsSection[] => [
    {
        key: 'members',
        title: t('settings:index.sections.members.title'),
        description: t('settings:index.sections.members.description'),
        href: (w) => `/${w}/settings/members`,
    },
    {
        key: 'billing',
        title: t('settings:index.sections.billing.title'),
        description: t('settings:index.sections.billing.description'),
        href: (w) => `/${w}/settings/billing`,
    },
    {
        key: 'apiTokens',
        title: t('settings:index.sections.apiTokens.title'),
        description: t('settings:index.sections.apiTokens.description'),
        href: (w) => `/${w}/settings/api-tokens`,
    },
    {
        key: 'carrierSettings',
        title: t('settings:index.sections.carrierSettings.title'),
        description: t('settings:index.sections.carrierSettings.description'),
        href: (w) => `/${w}/settings/shipping`,
    },
    {
        key: 'pipeline',
        title: t('settings:index.sections.pipeline.title'),
        description: t('settings:index.sections.pipeline.description'),
        href: (w) => `/${w}/pipeline`,
    },
    {
        key: 'preferences',
        title: t('settings:index.sections.preferences.title'),
        description: t('settings:index.sections.preferences.description'),
        href: (w) => `/${w}/settings/preferences`,
    },
    {
        key: 'sentEmails',
        title: t('settings:index.sections.sentEmails.title'),
        description: t('settings:index.sections.sentEmails.description'),
        href: (w) => `/${w}/settings/sent-emails`,
    },
    {
        key: 'dataExports',
        title: t('settings:index.sections.dataExports.title'),
        description: t('settings:index.sections.dataExports.description'),
        href: (w) => `/${w}/settings/data-export`,
    },
    {
        key: 'webhooks',
        title: t('settings:index.sections.webhooks.title'),
        description: t('settings:index.sections.webhooks.description'),
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
    const { t } = useTranslation('settings');
    const sections = useMemo(() => buildSections(t), [t]);

    return (
        <>
            <Head title={t('settings:index.title')} />

            <div className="flex flex-col gap-6">
                <PageHeader title={t('settings:index.title')} />

                <div className="grid gap-4 sm:grid-cols-2">
                    {sections.filter((section) => can[section.key]).map((section) => (
                        <div key={section.key} className="flex flex-col gap-3 rounded-lg border border-border bg-surface p-4">
                            <h2 className="text-sm font-medium text-text">{section.title}</h2>

                            <p className="text-sm text-text-2">{section.description}</p>

                            {workspace && (
                                <div>
                                    {/* SC 2.4.4 / 4.1.2 — „Open" identic pe fiecare card ar da N linkuri cu
                                        același nume accesibil, fără context (.ai/rules/frontend.md, „Un nume
                                        accesibil repetat pe fiecare rând..."). Discriminatorul e un sufix
                                        `sr-only`, nu `aria-label` (SC 2.5.3 — textul vizibil rămâne primul). */}
                                    <ButtonLink href={section.href(workspace.slug)} variant="secondary" prefetch>
                                        {t('settings:index.open')}
                                        <span className="sr-only"> {section.title}</span>
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
