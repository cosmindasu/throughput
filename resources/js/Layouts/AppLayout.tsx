import type { PropsWithChildren } from 'react';

/**
 * Shell de aplicație pentru paginile autentificate.
 *
 * Sprint 0: doar scheletul care consumă tokens de design (bg/surface/border/
 * text) — fără navigație reală. Sidebar-ul, comutatorul de workspace și
 * comanda rapidă (⌘K) vin în Faza 1, o dată cu tenancy + RBAC
 * (plan-implementare.md §7-§8).
 */
export default function AppLayout({ children }: PropsWithChildren) {
    return (
        <div className="min-h-screen bg-bg text-text">
            <a
                href="#main-content"
                className="sr-only focus:not-sr-only focus:absolute focus:top-4 focus:left-4 focus:z-50 focus:rounded-md focus:bg-accent-fill focus:px-4 focus:py-2 focus:text-accent-on focus:outline-2 focus:outline-offset-2 focus:outline-focus"
            >
                Skip to content
            </a>

            <header className="border-b border-border bg-surface">
                {/* Navigație reală (sidebar, comutator de workspace, comandă rapidă) — Faza 1 */}
            </header>

            <main id="main-content" className="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
                {children}
            </main>
        </div>
    );
}
