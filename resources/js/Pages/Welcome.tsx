import { Head } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { ButtonLink } from '@/Components/Button';
import AppLayout from '@/Layouts/AppLayout';

/**
 * FR-PUB-01 (specs.md §4.1) — pagina de start. Până la 2026-09-28 aici a rămas ecranul de
 * verificare a design-system-ului din Sprint 0: un buton `type="button"` fără handler și un
 * „Orders today: 1.284" hardcodat, adică o cifră inventată pe prima pagină a unui demo de
 * portofoliu. Auditul extern din aceeași zi l-a semnalat; cerința exista deja, neimplementată.
 *
 * Ce cere FR-PUB-01, verificat punct cu punct:
 *  - cel mult 3 propoziții despre ce e produsul și pentru cine (criteriul de acceptanță cere
 *    „descrierea de o propoziție" — e una singură, ca să le satisfacă pe amândouă);
 *  - UN SINGUR CTA principal, „Enter the demo" → `/login`, fără pas intermediar;
 *  - notificarea de site demonstrativ vizibilă fără scroll — vine din `AppLayout`
 *    (FR-PUB-03, persistentă pe toate paginile), nu se repetă aici;
 *  - fără pricing, fără formular de contact, fără signup (§2.2 — nu există flux comercial).
 *
 * Decizia proprietarului (2026-09-13): vizitatorul anonim NU urmează `prefers-color-scheme` —
 * tema închisă e implicită FIX pentru cine n-a ales nimic (specs.md §15.6, „pe ea se fac
 * captura de portofoliu și demo-ul public"). Randarea vine strict server-side din
 * `App\Support\ThemePreference`; niciun `useThemeSync` aici.
 *
 * Textul vizibil trece prin `common` (ADR-022/§15.8): interfața e bilingvă EN(implicit)+FR,
 * inclusiv pagina publică. „Throughput" (marcă) rămâne hardcodat, identic în ambele limbi.
 */
export default function Welcome() {
    const { t } = useTranslation('common');

    return (
        <>
            <Head title={t('common:welcome.title')} />

            <div className="flex flex-col gap-8 py-8">
                <h1 className="text-3xl font-semibold text-text">Throughput</h1>

                <p className="max-w-prose text-lg text-text-2">{t('common:welcome.description')}</p>

                <div>
                    <ButtonLink href="/login" variant="primary">
                        {t('common:welcome.enterDemo')}
                    </ButtonLink>
                </div>
            </div>
        </>
    );
}

Welcome.layout = (page: ReactNode) => <AppLayout>{page}</AppLayout>;
