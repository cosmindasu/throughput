import { useState } from 'react';
import { useTranslation } from 'react-i18next';

/**
 * Banner demo persistent (FR-PUB-03) — pe toate paginile, publice și
 * autentificate (`GuestLayout` și `AppLayout` îl randează amândouă).
 *
 * Nu se poate închide definitiv: „închiderea” doar restrânge bannerul la o
 * linie subțire, fără să-l scoată din DOM (`display:none` ar însemna
 * „eliminat din DOM” pentru scopul criteriului de acceptanță, deci nu se
 * folosește aici — doar padding/font-size mai mici).
 *
 * Tokens de info (nu danger/roșu): mesajul nu trebuie să sugereze o eroare a
 * aplicației (specs.md §4.3).
 */
export default function DemoBanner() {
    const { t } = useTranslation('common');
    const [collapsed, setCollapsed] = useState(false);

    return (
        <div className={`w-full border-b border-border bg-info-tint text-info ${collapsed ? 'py-0.5' : 'py-2'}`}>
            <div className="mx-auto flex max-w-7xl items-center justify-between gap-4 px-4 sm:px-6 lg:px-8">
                {collapsed ? (
                    <span className="truncate text-xs font-medium">{t('common:demoBanner.collapsed')}</span>
                ) : (
                    <p className="text-sm">{t('common:demoBanner.full')}</p>
                )}

                <button
                    type="button"
                    onClick={() => setCollapsed((value) => !value)}
                    aria-expanded={!collapsed}
                    className="shrink-0 rounded-md px-2 py-1 text-xs font-medium underline-offset-2 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus"
                >
                    {collapsed ? t('common:demoBanner.show') : t('common:demoBanner.minimize')}
                </button>
            </div>
        </div>
    );
}
