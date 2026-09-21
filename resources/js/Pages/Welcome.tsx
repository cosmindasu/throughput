import { Head } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { useLocale } from '@/hooks/useLocale';
import AppLayout from '@/Layouts/AppLayout';
import { formatNumber } from '@/lib/format';

export default function Welcome() {
    // Decizia proprietarului (2026-09-13): vizitatorul anonim NU mai urmează
    // `prefers-color-scheme` — tema închisă e implicită FIX pentru cine n-a ales
    // nimic (specs.md §15.6, „pe ea se fac captura de portofoliu și demo-ul public").
    // Randarea vine strict server-side din App\Support\ThemePreference (cookie, dacă
    // există, altfel închis) — niciun `useThemeSync` aici. „System" rămâne o opțiune
    // reală, dar doar pentru cine o alege explicit din comutator, pe un ecran
    // AUTENTIFICAT (resources/js/Components/ThemeToggle.tsx); un vizitator anonim n-are
    // cum să o aleagă, deci n-are ce sincroniza.
    //
    // Textul vizibil trece prin `common` (namespace-ul shell-ului, ADR-022/§15.8): de la
    // Val 3 al Lotului I18N, interfața e bilingvă EN(implicit)+FR, inclusiv pagina publică
    // „/" — premisa „piața e exclusiv internațională, interfața e în engleză" din
    // specs.md§0 anterior v1.22 e cea înlocuită de ADR-022, nu una încă în vigoare aici.
    // „Throughput" (marcă) rămâne hardcodat, identic în ambele limbi — un nume propriu nu
    // se traduce.
    const { t } = useTranslation('common');
    const locale = useLocale();

    return (
        <>
            <Head title={t('common:welcome.title')} />

            <div className="flex flex-col gap-8">
                <h1 className="text-2xl font-semibold text-text">Throughput</h1>

                <p className="max-w-prose text-text-2">{t('common:welcome.description')}</p>

                <div className="flex flex-wrap items-center gap-4">
                    <button
                        type="button"
                        className="rounded-md bg-accent-fill px-4 py-2 text-sm font-medium text-accent-on transition-colors hover:bg-accent-fill-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus"
                    >
                        {t('common:welcome.primaryAction')}
                    </button>

                    <span className="inline-flex items-center gap-1.5 rounded-full bg-info-tint px-2.5 py-1 text-xs font-medium text-info">
                        <span aria-hidden="true">●</span>
                        {t('common:welcome.statusConfirmed')}
                    </span>

                    <span className="text-sm text-text-2">
                        {t('common:welcome.ordersToday')} <span className="numeric text-text">{formatNumber(1284, locale)}</span>
                    </span>
                </div>
            </div>
        </>
    );
}

Welcome.layout = (page: ReactNode) => <AppLayout>{page}</AppLayout>;
