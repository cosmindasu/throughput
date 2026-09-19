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
                    // BR-HELP-02 — indicii de primă vizită respinse, per utilizator
                    // (nu per tenant — supraviețuiesc comutării de workspace, la fel
                    // ca `theme`, FR-PREF-02). Chei libere (`help-panel-intro`, ...),
                    // validate strict server-side la POST /hints/{key}.
                    dismissedHints: string[];
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
            // FR-TEN-05 — indicatorul numeric din `AppLayout` pe intrarea „Unassigned",
            // calculat doar pentru Owner/Manager (`0` pentru restul rolurilor, nu absent —
            // vezi `App\Http\Middleware\HandleInertiaRequests::share()`).
            unassignedRecordsCount: number;
            flash: {
                success: string | null;
                error: string | null;
                // FR-VIEW-02 — „vederea Team folosită ca implicit a fost ștearsă", ton
                // neutru, DISTINCT de `error`: nu e o greșeală a persoanei care o vede.
                notice: string | null;
            };
            demoMode: boolean;
            theme: 'light' | 'dark';
        };
    }
}
