import { Head } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import PageHeader from '@/Components/PageHeader';
import LocaleToggle from '@/Components/LocaleToggle';
import ThemeToggle from '@/Components/ThemeToggle';
import AppLayout from '@/Layouts/AppLayout';

/**
 * Settings → Preferences (specs.md §15.6, §15.8) — vizibilă TUTUROR rolurilor, inclusiv
 * Viewer (BR-PREF-02/FR-I18N-01). Rândul de temă folosește ACELAȘI `ThemeToggle` montat
 * în bara de sus (AppLayout) — un singur loc care știe cum se comută tema. Rândul de
 * limbă e simetric, cu `LocaleToggle`.
 *
 * Traducerea titlului paginii și a rândului de limbă e SCHELETUL Valului 1 („Lot I18N",
 * ADR-022): dovada lanțului complet cap-coadă (`<html lang>` → `lib/i18n.ts` → catalog →
 * randare). Rândul de temă a fost extras la Valul 3, odată cu restul suprafeței — vezi
 * `theme.heading`/`theme.description` în `locales/{en,fr}/settings.json`, adăugate ca
 * SIBLING la `language.*`, nu ca rescriere a lui.
 */
export default function SettingsPreferences() {
    const { t } = useTranslation('settings');

    return (
        <>
            <Head title={t('settings:title')} />

            <div className="flex flex-col gap-6">
                <PageHeader title={t('settings:title')} />

                <section className="flex flex-wrap items-center justify-between gap-4 rounded-lg border border-border bg-surface p-4">
                    <div>
                        <h2 className="text-sm font-medium text-text">{t('settings:theme.heading')}</h2>
                        <p className="text-sm text-text-2">{t('settings:theme.description')}</p>
                    </div>

                    <ThemeToggle />
                </section>

                <section className="flex flex-wrap items-center justify-between gap-4 rounded-lg border border-border bg-surface p-4">
                    <div>
                        <h2 className="text-sm font-medium text-text">{t('settings:language.heading')}</h2>
                        <p className="text-sm text-text-2">{t('settings:language.description')}</p>
                        {/* Caz de pluralizare (FR-I18N-02/03) — motorul CLDR al i18next, nu
                            un `=== 1 ? singular : plural` scris de mână (cele trei tipare
                            hardcodate documentate în ADR-022 rămân de refactorizat în Val 3).
                            Verificat: franceza tratează 0 ȘI 1 ca singular, engleza doar 1. */}
                        <p className="text-xs text-text-2">
                            {t('settings:language.available', { count: 2 })}
                        </p>
                    </div>

                    <LocaleToggle />
                </section>
            </div>
        </>
    );
}

SettingsPreferences.layout = (page: ReactNode) => <AppLayout>{page}</AppLayout>;
