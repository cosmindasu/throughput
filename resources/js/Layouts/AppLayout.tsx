import { Link, router, usePage } from '@inertiajs/react';
import type { TFunction } from 'i18next';
import { useEffect, useMemo, useRef, type PropsWithChildren } from 'react';
import { useTranslation } from 'react-i18next';
import DemoBanner from '@/Components/DemoBanner';
import FlashMessages from '@/Components/FlashMessages';
import GlobalSearch from '@/Components/GlobalSearch';
import HelpPanel from '@/Components/HelpPanel';
import Icon, { type IconName } from '@/Components/Icon';
import ListUpdateAnnouncer from '@/Components/ListUpdateAnnouncer';
import SubscriptionBanner from '@/Components/SubscriptionBanner';
import ThemeToggle from '@/Components/ThemeToggle';
import WorkspaceSwitcher from '@/Components/WorkspaceSwitcher';

interface NavItem {
    label: string;
    permission: string;
    href: (workspaceSlug: string) => string;
    icon: IconName;
}

/**
 * Etichete TEXT, nu iconițe fără text (specs.md §21.3, FR-DEMO-01) — iconul de pe fiecare
 * intrare ÎNSOȚEȘTE eticheta, nu o înlocuiește, deci cerința rămâne îndeplinită: este marcat
 * `aria-hidden` în `Icon.tsx`, așa că numele accesibil al linkului rămâne exact textul vizibil
 * (SC 2.5.3 Label in Name — vezi regula din `.ai/rules/frontend.md`). Fiecare
 * intrare e conditionată de `navigation` — un buton fără drept e ABSENT, nu
 * dezactivat (FR-RBAC-01, §7.3). Căile sunt literale (nu există Ziggy în
 * proiect — vezi nota din raport), construite după convenția de rute web din
 * plan-implementare.md §0 (`/{workspace}/{modul}`).
 *
 * Fabrică parametrizată pe `t` (§6, brief Val 3) — tabloul trăia la nivel de modul, unde
 * `t()` nu are o componentă montată de care să se lege; memoizată în corpul componentei,
 * mai jos.
 */
const buildNavItems = (t: TFunction): NavItem[] => [
    { label: t('common:nav.accounts'), permission: 'accounts.view', href: (w) => `/${w}/accounts`, icon: 'accounts' },
    { label: t('common:nav.contacts'), permission: 'contacts.view', href: (w) => `/${w}/contacts`, icon: 'contacts' },
    { label: t('common:nav.deals'), permission: 'deals.view', href: (w) => `/${w}/deals`, icon: 'deals' },
    { label: t('common:nav.products'), permission: 'products.view', href: (w) => `/${w}/products`, icon: 'products' },
    { label: t('common:nav.orders'), permission: 'orders.view', href: (w) => `/${w}/orders`, icon: 'orders' },
    { label: t('common:nav.invoices'), permission: 'invoices.view', href: (w) => `/${w}/invoices`, icon: 'invoices' },
    { label: t('common:nav.reports'), permission: 'reports.view', href: (w) => `/${w}/reports`, icon: 'reports' },
    // §7.4, rândul „Import CSV": CRUD pentru Owner/Manager, „—" pentru Agent și Viewer —
    // singura intrare din navigație pe care Agentul NU o vede deloc, alături de Unassigned.
    { label: t('common:nav.imports'), permission: 'imports.view', href: (w) => `/${w}/imports`, icon: 'imports' },
    // FR-TEN-05 — Owner/Manager (`unassigned.view`, §6.4.1). Indicatorul numeric se
    // randează separat, mai jos, lângă acest link — `unassignedRecordsCount` e un prop
    // comun distinct, nu parte din `navigation`.
    { label: t('common:nav.unassigned'), permission: 'unassigned.view', href: (w) => `/${w}/unassigned`, icon: 'unassigned' },
    // FR-AUD-03, §17.3 — jurnalul la nivel de tenant. Permisiunea e una COMBINATĂ, calculată
    // server-side în `HandleInertiaRequests::navigationPermissions()`: Owner/Manager au
    // `activity_log.view` (tot tenantul), Agentul are `activity_log.view_own` (doar acțiunile
    // proprii), iar `NavItem` verifică o singură cheie per intrare. Viewer-ul n-are niciuna,
    // deci nu vede linkul (§7.4).
    { label: t('common:nav.activityLog'), permission: 'activity_log.any_view', href: (w) => `/${w}/activity`, icon: 'activity' },
    { label: t('common:nav.settings'), permission: 'settings.view', href: (w) => `/${w}/settings`, icon: 'settings' },
];

/**
 * Shell de aplicație pentru paginile autentificate (plan-implementare.md
 * §7.4). Header cu comutator de workspace + navigație principală, ambele
 * randate din props comune (`workspace`, `workspaces`, `navigation`) — nimic
 * recalculat din rolul brut al utilizatorului (§1.2 regula 2, FR-RBAC-01).
 */
export default function AppLayout({ children }: PropsWithChildren) {
    const { t } = useTranslation('common');
    const page = usePage();
    const { auth, workspace, workspaces, navigation, unassignedRecordsCount } = page.props;
    const { url } = page;
    const navItems = useMemo(() => buildNavItems(t), [t]);

    const logout = () => {
        router.post('/logout');
    };

    // Într-un SPA, navigarea nu mută focusul singură: după un `Link`, focusul rămâne pe
    // declanșatorul care tocmai a dispărut din DOM, iar un utilizator de tastatură sau de
    // cititor de ecran reia pagina nouă de la început (`.ai/rules/frontend.md`, „Focusul nu
    // se pierde niciodată pe `<body>`"). Mutăm focusul pe `<main>` la fiecare schimbare
    // REALĂ de pagină.
    //
    // Cheia e componenta + calea, NU fiecare vizită Inertia: un `router.reload()` de polling
    // (progresul unei operații în masă, eticheta de curierat) și o schimbare de filtru cu
    // `preserveState` păstrează aceeași componentă și aceeași cale, deci nu fură focusul din
    // câmpul în care tocmai scrie utilizatorul. Prima randare nu mută nimic (`previousKey`
    // pornește chiar de la cheia curentă) — altfel fiecare încărcare completă ar sări peste
    // header și peste linkul „Skip to content".
    const mainRef = useRef<HTMLElement>(null);
    const navigationKey = `${page.component}|${url.split('?')[0]}`;
    const previousKey = useRef(navigationKey);

    useEffect(() => {
        if (previousKey.current === navigationKey) {
            return;
        }

        previousKey.current = navigationKey;
        mainRef.current?.focus();
    }, [navigationKey]);

    return (
        // Coloană flex, nu doar `min-h-screen`: cu `min-h-screen` singur copiii curg normal,
        // iar pe o pagină cu puțin conținut footer-ul urcă imediat sub `<main>` și lasă gol
        // dedesubt. `flex-1` pe `<main>` îi dă restul înălțimii, deci footer-ul stă jos —
        // fără `position: fixed`, care l-ar scoate din flux și l-ar suprapune peste conținut
        // pe paginile lungi.
        <div className="flex min-h-screen flex-col bg-bg text-text">
            {/* A11Y-08 — PRIMUL element focusabil al paginii, înaintea `DemoBanner`/
                `SubscriptionBanner` (care randează, amândouă, propriile controale
                focusabile pe fiecare pagină). Altfel un utilizator de tastatură are nevoie
                de 1-2 Tab-uri suplimentare înainte să ajungă la mecanismul de bypass
                (SC 2.4.1) — găsit la audit, impact redus, dar ieftin de corectat. */}
            <a
                href="#main-content"
                className="sr-only focus:not-sr-only focus:absolute focus:top-4 focus:left-4 focus:z-50 focus:rounded-md focus:bg-accent-fill focus:px-4 focus:py-2 focus:text-accent-on focus:outline-2 focus:outline-offset-2 focus:outline-focus"
            >
                {t('common:nav.skipToContent')}
            </a>

            <DemoBanner />
            {/* specs.md §12.2 — sub DemoBanner, deasupra header-ului: pe orice pagină, nu
                doar Billing. `canceled` nu ajunge niciodată aici — `EnsureSubscriptionAccess`
                redirectează server-side către Settings/Billing/Index înainte de randare. */}
            <SubscriptionBanner />

            <header className="border-b border-border bg-surface">
                <div className="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-4 px-4 py-3 sm:px-6 lg:px-8">
                    <div className="flex items-center gap-4">
                        <span className="text-lg font-semibold text-text">Throughput</span>
                        <WorkspaceSwitcher current={workspace} workspaces={workspaces} />
                    </div>

                    {auth.user && (
                        <div className="flex items-center gap-3">
                            <GlobalSearch />
                            <HelpPanel />
                            <ThemeToggle />
                            <span
                                aria-hidden="true"
                                className="flex h-8 w-8 items-center justify-center rounded-full bg-accent-tint text-xs font-medium text-accent-text"
                            >
                                {auth.user.initials}
                            </span>
                            <span className="hidden text-sm text-text-2 sm:inline">{auth.user.name}</span>
                            <button
                                type="button"
                                onClick={logout}
                                className="rounded-md border border-control px-3 py-1.5 text-sm text-text-2 transition-colors hover:bg-row-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus"
                            >
                                {t('common:nav.logOut')}
                            </button>
                        </div>
                    )}
                </div>

                <nav aria-label={t('common:nav.primary')} className="border-t border-border-soft">
                    <div className="mx-auto flex max-w-7xl flex-wrap gap-1 px-4 sm:px-6 lg:px-8">
                        {navItems.filter((item) => navigation[item.permission]).map((item) => {
                            const href = workspace ? item.href(workspace.slug) : '#';
                            const active = workspace ? url.startsWith(item.href(workspace.slug)) : false;

                            const showUnassignedBadge = item.permission === 'unassigned.view' && unassignedRecordsCount > 0;

                            return (
                                <Link
                                    key={item.permission}
                                    href={href}
                                    // FR-PERF-02 — pagina e deja pe drum când cursorul ajunge pe link.
                                    prefetch
                                    // Eticheta accesibilă include numărul o SINGURĂ dată (nu și pe
                                    // badge-ul vizual, marcat `aria-hidden`) — altfel un cititor de
                                    // ecran ar anunța „Unassigned 3 unassigned records", redundant.
                                    // Acordul de plural (engleză: 2 categorii; franceză: 3, cu „many"
                                    // pentru milioane exacte) e motorul CLDR, nu concatenare de mână
                                    // (capcana documentată în ADR-022 / brief Val 3, §3) — șirul întreg
                                    // e o singură cheie `_one`/`_many`/`_other`, nu două flexiuni lipite.
                                    aria-label={
                                        showUnassignedBadge
                                            ? t('common:nav.unassignedBadge', { label: item.label, count: unassignedRecordsCount })
                                            : undefined
                                    }
                                    className={`inline-flex items-center gap-2 border-b-2 px-3 py-2.5 text-sm font-medium transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus ${
                                        active
                                            ? 'border-accent-fill text-accent-text'
                                            : 'border-transparent text-text-2 hover:text-text'
                                    }`}
                                >
                                    <Icon name={item.icon} />
                                    {item.label}
                                    {showUnassignedBadge && (
                                        <span
                                            aria-hidden="true"
                                            // Fără `ml-*`: de când linkul e `inline-flex`, `gap-2` de pe el dă deja distanța —
                                            // o margine peste ea s-ar aduna la gap și ar dubla spațiul.
                                            className="inline-flex min-w-[1.25rem] items-center justify-center rounded-full bg-accent-fill px-1.5 py-0.5 text-xs font-semibold text-accent-on numeric"
                                        >
                                            {unassignedRecordsCount}
                                        </span>
                                    )}
                                </Link>
                            );
                        })}
                    </div>
                </nav>
            </header>

            {/*
                `tabIndex={-1}` ca `<main>` să poată primi focus programatic (și ca ținta
                linkului „Skip to content" să funcționeze în toate browserele). Inelul rămâne
                pe `focus-visible`, nu ascuns cu `outline-none`: cine navighează cu tastatura
                vede unde a ajuns, cine dă click nu vede un contur pe toată pagina.
            */}
            <main
                id="main-content"
                ref={mainRef}
                tabIndex={-1}
                className="mx-auto w-full max-w-7xl flex-1 px-4 py-6 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus sm:px-6 lg:px-8"
            >
                <FlashMessages />
                {children}
            </main>

            {/* SC 4.1.3 — anunțul de „lista s-a schimbat" (paginare, filtre, sortare) stă
                AICI, în layout-ul persistent, nu în componenta de pagină: aceea se remontează
                la fiecare navigare GET (`key: Date.now()`), deci o regiune live montată acolo
                nu apucă niciodată să raporteze o schimbare. Vezi `.ai/rules/frontend.md`. */}
            <ListUpdateAnnouncer />

            {/* GDPR-06 — link către Politica de confidențialitate/Termeni, în `<footer>`,
                nu în `<main>`: nu fac parte din conținutul paginii curente. */}
            <footer className="border-t border-border bg-surface">
                <div className="mx-auto flex max-w-7xl flex-wrap gap-4 px-4 py-4 text-xs text-text-2 sm:px-6 lg:px-8">
                    <Link
                        href="/privacy"
                        className="rounded-sm hover:text-text hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus"
                    >
                        {t('common:footer.privacyLink')}
                    </Link>
                    <Link
                        href="/terms"
                        className="rounded-sm hover:text-text hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus"
                    >
                        {t('common:footer.termsLink')}
                    </Link>
                </div>
            </footer>
        </div>
    );
}
