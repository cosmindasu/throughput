import { Link, router, usePage } from '@inertiajs/react';
import type { PropsWithChildren } from 'react';
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
    const { auth, workspace, workspaces, navigation } = page.props;
    const { url } = page;

    const logout = () => {
        router.post('/logout');
    };

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

                            return (
                                <Link
                                    key={item.permission}
                                    href={href}
                                    // FR-PERF-02 — pagina e deja pe drum când cursorul ajunge pe link.
                                    prefetch
                                    className={`border-b-2 px-3 py-2.5 text-sm font-medium transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus ${
                                        active
                                            ? 'border-accent-fill text-accent-text'
                                            : 'border-transparent text-text-2 hover:text-text'
                                    }`}
                                >
                                    {item.label}
                                </Link>
                            );
                        })}
                    </div>
                </nav>
            </header>

            <main id="main-content" className="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
                <FlashMessages />
                {children}
            </main>
        </div>
    );
}
