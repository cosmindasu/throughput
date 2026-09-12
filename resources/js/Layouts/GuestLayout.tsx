import type { PropsWithChildren } from 'react';
import DemoBanner from '@/Components/DemoBanner';

/**
 * Shell minimal pentru paginile neautentificate (login, recuperare parolă —
 * Faza 1, specs.md §4.5). Fără navigație — doar centrare + tokens de design.
 *
 * `DemoBanner` e randat și aici, nu doar în `AppLayout`: FR-PUB-03 cere
 * bannerul pe „toate paginile (publice și autentificate)”, iar login e o
 * pagină publică.
 */
export default function GuestLayout({ children }: PropsWithChildren) {
    return (
        <div className="flex min-h-screen flex-col bg-bg text-text">
            <DemoBanner />

            <div className="flex flex-1 items-center justify-center px-4 py-12">
                <div className="w-full max-w-md rounded-lg border border-border bg-surface p-8 shadow-sm">
                    {children}
                </div>
            </div>
        </div>
    );
}
