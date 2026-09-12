import '@inertiajs/core';

/**
 * Augmentare a tipurilor Inertia pentru props-urile comune partajate din
 * `App\Http\Middleware\HandleInertiaRequests::share()`.
 *
 * Faza 1: `auth.user`, `workspace`, `workspaces` și `navigation` se adaugă
 * acum, o dată cu tenancy/RBAC (§1.2 regula 3 din plan-implementare.md).
 * `navigation` rămâne `Record<string, boolean>` — nu un union de chei literale
 * — pentru că fazele următoare adaugă module noi fără să atingă acest fișier.
 *
 * `can` NU e aici: e propul fiecărei pagini (§1.2 regula 2), declarat în
 * interfața `*PageProps` din `generated.d.ts`. Partajat global, s-ar fi bătut
 * cu cel al paginii — Inertia combină cele două niveluri superficial.
 */
declare module '@inertiajs/core' {
    export interface InertiaConfig {
        // Flash-ul Inertia 3 (`Inertia::flash()`), distinct de propul comun `flash`
        // (success/error): nu intră în starea din istoric, deci nu reapare la „Back".
        flashDataType: {
            // App\Support\Contacts\DuplicateContactEmail — US-CRM-01.
            duplicateEmail?: {
                field: string;
                accountId: string;
                accountName: string;
            };
        };
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
            navigation: Record<string, boolean>;
            flash: {
                success: string | null;
                error: string | null;
            };
            demoMode: boolean;
            theme: 'light' | 'dark';
        };
    }
}
