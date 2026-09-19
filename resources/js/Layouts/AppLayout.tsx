import { Link, router, usePage } from '@inertiajs/react';
import { useEffect, useRef, type PropsWithChildren } from 'react';
import DemoBanner from '@/Components/DemoBanner';
import FlashMessages from '@/Components/FlashMessages';
import GlobalSearch from '@/Components/GlobalSearch';
import HelpPanel from '@/Components/HelpPanel';
import ThemeToggle from '@/Components/ThemeToggle';
import WorkspaceSwitcher from '@/Components/WorkspaceSwitcher';

interface NavItem {
    label: string;
    permission: string;
    href: (workspaceSlug: string) => string;
}

/**
 * Etichete TEXT, nu iconițe fără text (specs.md §21.3, FR-DEMO-01). Fiecare
 * intrare e conditionată de `navigation` — un buton fără drept e ABSENT, nu
 * dezactivat (FR-RBAC-01, §7.3). Căile sunt literale (nu există Ziggy în
 * proiect — vezi nota din raport), construite după convenția de rute web din
 * plan-implementare.md §0 (`/{workspace}/{modul}`).
 */
const NAV_ITEMS: NavItem[] = [
    { label: 'Accounts', permission: 'accounts.view', href: (w) => `/${w}/accounts` },
    { label: 'Contacts', permission: 'contacts.view', href: (w) => `/${w}/contacts` },
    { label: 'Deals', permission: 'deals.view', href: (w) => `/${w}/deals` },
    { label: 'Products', permission: 'products.view', href: (w) => `/${w}/products` },
    { label: 'Orders', permission: 'orders.view', href: (w) => `/${w}/orders` },
    { label: 'Invoices', permission: 'invoices.view', href: (w) => `/${w}/invoices` },
    { label: 'Reports', permission: 'reports.view', href: (w) => `/${w}/reports` },
    // FR-TEN-05 — Owner/Manager (`unassigned.view`, §6.4.1). Indicatorul numeric se
    // randează separat, mai jos, lângă acest link — `unassignedRecordsCount` e un prop
    // comun distinct, nu parte din `navigation`.
    { label: 'Unassigned', permission: 'unassigned.view', href: (w) => `/${w}/unassigned` },
    { label: 'Settings', permission: 'settings.view', href: (w) => `/${w}/settings` },
];

/**
 * Shell de aplicație pentru paginile autentificate (plan-implementare.md
 * §7.4). Header cu comutator de workspace + navigație principală, ambele
 * randate din props comune (`workspace`, `workspaces`, `navigation`) — nimic
 * recalculat din rolul brut al utilizatorului (§1.2 regula 2, FR-RBAC-01).
 */
export default function AppLayout({ children }: PropsWithChildren) {
    const page = usePage();
    const { auth, workspace, workspaces, navigation, unassignedRecordsCount } = page.props;
    const { url } = page;

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
        <div className="min-h-screen bg-bg text-text">
            <DemoBanner />

            <a
                href="#main-content"
                className="sr-only focus:not-sr-only focus:absolute focus:top-4 focus:left-4 focus:z-50 focus:rounded-md focus:bg-accent-fill focus:px-4 focus:py-2 focus:text-accent-on focus:outline-2 focus:outline-offset-2 focus:outline-focus"
            >
                Skip to content
            </a>

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
                                Log out
                            </button>
                        </div>
                    )}
                </div>

                <nav aria-label="Primary" className="border-t border-border-soft">
                    <div className="mx-auto flex max-w-7xl flex-wrap gap-1 px-4 sm:px-6 lg:px-8">
                        {NAV_ITEMS.filter((item) => navigation[item.permission]).map((item) => {
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
                                    // Acord de număr: „1 record needs…" / „N records need…".
                                    aria-label={
                                        showUnassignedBadge
                                            ? `${item.label}, ${unassignedRecordsCount} record${unassignedRecordsCount === 1 ? '' : 's'} need${unassignedRecordsCount === 1 ? 's' : ''} a new owner`
                                            : undefined
                                    }
                                    className={`border-b-2 px-3 py-2.5 text-sm font-medium transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus ${
                                        active
                                            ? 'border-accent-fill text-accent-text'
                                            : 'border-transparent text-text-2 hover:text-text'
                                    }`}
                                >
                                    {item.label}
                                    {showUnassignedBadge && (
                                        <span
                                            aria-hidden="true"
                                            className="ml-1.5 inline-flex min-w-[1.25rem] items-center justify-center rounded-full bg-accent-fill px-1.5 py-0.5 text-xs font-semibold text-accent-on numeric"
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
                className="mx-auto max-w-7xl px-4 py-6 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus sm:px-6 lg:px-8"
            >
                <FlashMessages />
                {children}
            </main>
        </div>
    );
}
