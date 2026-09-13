import { Head } from '@inertiajs/react';
import type { ReactNode } from 'react';
import AppLayout from '@/Layouts/AppLayout';

export default function Welcome() {
    // Decizia proprietarului (2026-09-13): vizitatorul anonim NU mai urmează
    // `prefers-color-scheme` — tema închisă e implicită FIX pentru cine n-a ales
    // nimic (specs.md §15.6, „pe ea se fac captura de portofoliu și demo-ul public").
    // Randarea vine strict server-side din App\Support\ThemePreference (cookie, dacă
    // există, altfel închis) — niciun `useThemeSync` aici. „System" rămâne o opțiune
    // reală, dar doar pentru cine o alege explicit din comutator, pe un ecran
    // AUTENTIFICAT (resources/js/Components/ThemeToggle.tsx); un vizitator anonim n-are
    // cum să o aleagă, deci n-are ce sincroniza.
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
