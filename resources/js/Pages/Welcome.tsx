import { Head, usePage } from '@inertiajs/react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import AppLayout from '@/Layouts/AppLayout';

const THEME_COOKIE = 'theme';
const ONE_YEAR_IN_SECONDS = 60 * 60 * 24 * 365;

function setThemeCookie(theme: 'light' | 'dark') {
    document.cookie = `${THEME_COOKIE}=${theme}; path=/; max-age=${ONE_YEAR_IN_SECONDS}; samesite=lax`;
}

/**
 * Comutator de temă minimal (Sprint 0).
 *
 * Scrie cookie-ul `theme` client-side și basculează clasa `.dark` direct pe
 * `<html>`, ca schimbarea să fie instantă și fără reload. La următoarea
 * navigare/randare server, `app.blade.php` citește același cookie și aplică
 * aceeași clasă înainte de primul paint (FR-PREF-03, fără licărire).
 *
 * Persistența server-side completă (coloana `users.theme`, FR-PREF-01/02) e
 * Faza 1 — nu se implementează acum, doar cookie-ul client.
 */
function ThemeToggle() {
    const { theme } = usePage().props;
    const [current, setCurrent] = useState<'light' | 'dark'>(theme);

    const toggle = () => {
        const next = current === 'dark' ? 'light' : 'dark';
        setCurrent(next);
        document.documentElement.classList.toggle('dark', next === 'dark');
        document.documentElement.style.colorScheme = next;
        setThemeCookie(next);
    };

    return (
        <button
            type="button"
            onClick={toggle}
            className="rounded-md border border-control px-3 py-1.5 text-sm text-text-2 transition-colors hover:bg-row-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus"
        >
            Theme: {current === 'dark' ? 'dark' : 'light'}
        </button>
    );
}

export default function Welcome() {
    return (
        <>
            {/*
             * Textul vizibil e în ENGLEZĂ, deliberat: piața e exclusiv
             * internațională, interfața e în engleză (specs.md §0, APP_LOCALE=en).
             * Comentariile și documentația rămân în română.
             */}
            <Head title="Home" />

            <div className="flex flex-col gap-8">
                <div className="flex items-center justify-between">
                    <h1 className="text-2xl font-semibold text-text">Throughput</h1>
                    <ThemeToggle />
                </div>

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
