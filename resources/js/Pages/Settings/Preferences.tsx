import { Head } from '@inertiajs/react';
import type { ReactNode } from 'react';
import PageHeader from '@/Components/PageHeader';
import ThemeToggle from '@/Components/ThemeToggle';
import AppLayout from '@/Layouts/AppLayout';

/**
 * Settings → Preferences (specs.md §15.6) — vizibilă TUTUROR rolurilor, inclusiv
 * Viewer (BR-PREF-02). Rândul de temă folosește ACELAȘI `ThemeToggle` montat în bara
 * de sus (AppLayout) — un singur loc care știe cum se comută tema.
 */
export default function SettingsPreferences() {
    return (
        <>
            <Head title="Preferences" />

            <div className="flex flex-col gap-6">
                <PageHeader title="Preferences" />

                <section className="flex flex-wrap items-center justify-between gap-4 rounded-lg border border-border bg-surface p-4">
                    <div>
                        <h2 className="text-sm font-medium text-text">Theme</h2>
                        <p className="text-sm text-text-2">
                            Choose how Throughput looks. System follows your device setting.
                        </p>
                    </div>

                    <ThemeToggle />
                </section>
            </div>
        </>
    );
}

SettingsPreferences.layout = (page: ReactNode) => <AppLayout>{page}</AppLayout>;
