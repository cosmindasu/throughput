import '@inertiajs/core';

/**
 * Augmentare a tipurilor Inertia pentru props-urile comune partajate din
 * `App\Http\Middleware\HandleInertiaRequests::share()`.
 *
 * Sprint 0: doar `flash`, `demoMode`, `theme` — singurele props justificate
 * la acest stadiu (plan-implementare.md §6 task 16). `auth.user`, `workspace`,
 * `workspaces`, `can` se adaugă aici în Faza 1, o dată cu tenancy/RBAC
 * (§1.2 regula 3 din plan-implementare.md).
 */
declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            flash: {
                success: string | null;
                error: string | null;
            };
            demoMode: boolean;
            theme: 'light' | 'dark';
        };
    }
}
