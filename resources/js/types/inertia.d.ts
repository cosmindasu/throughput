import '@inertiajs/core';

/**
 * Augmentare a tipurilor Inertia pentru props-urile comune partajate din
 * `App\Http\Middleware\HandleInertiaRequests::share()`.
 *
 * Faza 1: `auth.user`, `workspace`, `workspaces` și `can` se adaugă acum, o
 * dată cu tenancy/RBAC (§1.2 regula 3 din plan-implementare.md). `can` rămâne
 * `Record<string, boolean>` — nu un union de chei literale — pentru că
 * fazele următoare adaugă permisiuni noi (`accounts.create`, etc.) fără să
 * atingă acest fișier.
 */
declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            auth: {
                user: {
                    id: string;
                    name: string;
                    email: string;
                    theme: 'system' | 'light' | 'dark';
                    initials: string;
                } | null;
            };
            workspace: {
                slug: string;
                name: string;
                industry: string | null;
            } | null;
            workspaces: Array<{
                slug: string;
                name: string;
            }>;
            can: Record<string, boolean>;
            flash: {
                success: string | null;
                error: string | null;
            };
            demoMode: boolean;
            theme: 'light' | 'dark';
        };
    }
}
