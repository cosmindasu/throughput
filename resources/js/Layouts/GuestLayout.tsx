import { Link } from '@inertiajs/react';
import type { PropsWithChildren } from 'react';
import { useTranslation } from 'react-i18next';
import DemoBanner from '@/Components/DemoBanner';

interface GuestLayoutProps extends PropsWithChildren {
    /**
     * Lățimea cardului central — `max-w-md` (login, recuperare parolă) e prea îngustă
     * pentru textul de pe `Pages/Legal/Privacy.tsx`/`Terms.tsx`, deci paginile legale o
     * suprascriu (`GuestLayout maxWidth="max-w-3xl"`). Implicit neschimbat, ca restul
     * ecranelor pe acest layout să arate identic.
     */
    maxWidth?: string;
}

/**
 * Shell minimal pentru paginile neautentificate (login, recuperare parolă —
 * Faza 1, specs.md §4.5; și, din GDPR-06, cele două pagini publice legale). Fără
 * navigație — doar centrare + tokens de design.
 *
 * `DemoBanner` e randat și aici, nu doar în `AppLayout`: FR-PUB-03 cere
 * bannerul pe „toate paginile (publice și autentificate)”, iar login e o
 * pagină publică.
 */
export default function GuestLayout({ children, maxWidth = 'max-w-md' }: GuestLayoutProps) {
    const { t } = useTranslation('common');

    return (
        <div className="flex min-h-screen flex-col bg-bg text-text">
            {/* A11Y-08 — primul element focusabil al paginii, înaintea bannerului de demo
                (consecvent cu `AppLayout.tsx`, care are aceeași corecție). */}
            <a
                href="#main-content"
                className="sr-only focus:not-sr-only focus:absolute focus:top-4 focus:left-4 focus:z-50 focus:rounded-md focus:bg-accent-fill focus:px-4 focus:py-2 focus:text-accent-on focus:outline-2 focus:outline-offset-2 focus:outline-focus"
            >
                {t('common:nav.skipToContent')}
            </a>

            <DemoBanner />

            {/* A11Y-09 — landmark `<main>`, consecvent cu `AppLayout.tsx`. */}
            <main id="main-content" className="flex flex-1 items-center justify-center px-4 py-12">
                <div className={`w-full ${maxWidth} rounded-lg border border-border bg-surface p-8 shadow-sm`}>{children}</div>
            </main>

            <footer className="border-t border-border bg-surface py-4">
                <div className={`mx-auto flex ${maxWidth} justify-center gap-4 px-4 text-xs text-text-2`}>
                    <Link href="/privacy" className="rounded-sm hover:text-text hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus">
                        {t('common:footer.privacyLink')}
                    </Link>
                    <Link href="/terms" className="rounded-sm hover:text-text hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus">
                        {t('common:footer.termsLink')}
                    </Link>
                </div>
            </footer>
        </div>
    );
}
