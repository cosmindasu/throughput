import { Head } from '@inertiajs/react';
import type { ReactNode } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import { useThemeSync } from '@/hooks/useThemeSync';

export default function Welcome() {
    // Vizitator anonim: nicio alegere persistată (fără `users.theme`), deci mereu,
    // implicit, „System" (specs.md §15.6) — corectează un prim-paint greșit pe un
    // dispozitiv nou și urmărește schimbările reale ale sistemului de operare.
    // Comutatorul cu trei stări (resources/js/Components/ThemeToggle.tsx) nu apare
    // aici: FR-PREF-01 îl cere doar pe ecranele AUTENTIFICATE — AppLayout îl ascunde
    // deja pentru vizitatori, la fel ca restul barei de sus.
    useThemeSync(true);

    return (
        <>
            {/*
             * Textul vizibil e în ENGLEZĂ, deliberat: piața e exclusiv
             * internațională, interfața e în engleză (specs.md §0, APP_LOCALE=en).
             * Comentariile și documentația rămân în română.
             */}
            <Head title="Home" />

            <div className="flex flex-col gap-8">
                <h1 className="text-2xl font-semibold text-text">Throughput</h1>

                <p className="max-w-prose text-text-2">
                    Sprint 0 scaffold. The design system — tokens for both themes,
                    self-hosted typefaces, tabular numerals — is already in place, before
                    any business module exists.
                </p>

                <div className="flex flex-wrap items-center gap-4">
                    <button
                        type="button"
                        className="rounded-md bg-accent-fill px-4 py-2 text-sm font-medium text-accent-on transition-colors hover:bg-accent-fill-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus"
                    >
                        Primary action
                    </button>

                    <span className="inline-flex items-center gap-1.5 rounded-full bg-info-tint px-2.5 py-1 text-xs font-medium text-info">
                        <span aria-hidden="true">●</span>
                        confirmed
                    </span>

                    <span className="text-sm text-text-2">
                        Orders today: <span className="numeric text-text">1,284</span>
                    </span>
                </div>
            </div>
        </>
    );
}

Welcome.layout = (page: ReactNode) => <AppLayout>{page}</AppLayout>;
