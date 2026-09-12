import type { PropsWithChildren } from 'react';

/**
 * Shell minimal pentru paginile neautentificate (login, recuperare parolă —
 * Faza 1, specs.md §4.5). Fără navigație — doar centrare + tokens de design.
 */
export default function GuestLayout({ children }: PropsWithChildren) {
    return (
        <div className="flex min-h-screen items-center justify-center bg-bg px-4 py-12 text-text">
            <div className="w-full max-w-md rounded-lg border border-border bg-surface p-8 shadow-sm">
                {children}
            </div>
        </div>
    );
}
