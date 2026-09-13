/**
 * Mirror MANUAL al claselor `App\Http\Resources\*`.
 *
 * Nu există generare automată în MVP — ar adăuga o dependință nevalidată
 * (plan-implementare.md §1.2 regula 5). În schimb:
 *
 *   1. Orice schimbare de formă a unui Resource PHP (`app/Http/Resources/`)
 *      se reflectă AICI, manual, imediat.
 *   2. ...ȘI în testul de contract Pest corespunzător, de exemplu:
 *
 *      $response->assertInertia(fn (Assert $page) => $page
 *          ->component('Deals/Show')
 *          ->has('deal.id')
 *          ->has('can.edit')
 *          ->has('can.delete')
 *      );
 *
 * Code review respinge orice PR care schimbă un Resource fără să actualizeze
 * ambele.
 *
 * Interfețele `*PageProps` de mai jos primesc explicit `[key: string]: unknown`
 * — nu din neglijență, ci pentru că `usePage<T>()` din `@inertiajs/react`
 * cere `T extends PageProps` (adică `{ [key: string]: unknown }`); fără
 * semnătura de index, `tsc` respinge orice interfață cu forma închisă
 * (verificat direct: eroare TS2344 „Index signature ... is missing").
 * Props-urile comune (`auth`, `workspace`, `workspaces`, `can`, `flash`,
 * `demoMode`, `theme`) NU sunt repetate aici — sunt augmentate o singură
 * dată în `inertia.d.ts` (§1.2 regula 3) și se adună automat la orice
 * `usePage<XPageProps>()`.
 */

// Auth/Login — FR-PUB-02.
export type DemoAccountRole = 'owner' | 'manager' | 'agent' | 'viewer';

export interface DemoAccount {
    role: DemoAccountRole;
    name: string;
    description: string;
}

export interface LoginPageProps {
    canResetPassword: boolean;
    status?: string;
    demoAccounts: DemoAccount[];
    [key: string]: unknown;
}

// Auth/ForgotPassword — FR-PUB-05.
export interface ForgotPasswordPageProps {
    status?: string;
    [key: string]: unknown;
}

// Auth/ResetPassword — FR-PUB-05.
export interface ResetPasswordPageProps {
    token: string;
    email: string;
    [key: string]: unknown;
}

// Dashboard — FR-DEMO-01.
export interface DashboardKpis {
    openPipelineValue: number;
    ordersThisMonth: number;
    overdueInvoices: {
        count: number;
        amount: number;
    };
    lowStockAlerts: number;
}

export interface ActivityItem {
    id: string;
    description: string;
    actor: string;
    at: string;
}

export interface DashboardPageProps {
    kpis: DashboardKpis;
    activity: ActivityItem[];
    [key: string]: unknown;
}

// Liste — plan §1.2 regulile 6-7: `App\Support\ListQuery` + paginare pe cursor.
export interface CursorPage<T> {
    data: T[];
    nextCursor: string | null;
    prevCursor: string | null;
}

// `ListQuery::toArray()` — starea canonică a unei liste, fără cursor.
export interface ListState {
    filter: Record<string, string>;
    sort: string;
}

// ── Accounts — FR-CRM-01…04, US-CRM-01…03 ────────────────────────────────────────


// ── Contacts — FR-CRM-02 ─────────────────────────────────────────────────────────


// ── Deals și kanban — FR-DEAL-01, FR-DEAL-03 ─────────────────────────────────────


// ── Pipeline și etape — FR-DEAL-02 ───────────────────────────────────────────────


// ── Settings și preferințe — FR-PREF-01…03 ───────────────────────────────────────


// ── Căutare globală — FR-SEARCH-01 ───────────────────────────────────────────────
// Mirror manual al `App\Services\Search\GlobalSearchService` (nu un Resource — JSON
// simplu, întors direct de `SearchController@index`, nicio pagină Inertia).

/**
 * `product` rămâne în uniune deși `SearchController` nu întoarce încă un grup
 * „Products" (Faza 3, plan §9 — fără ecrane de produs în Faza 2, deci fără link):
 * un item „recent" poate fi de tip produs de îndată ce o pagină de detaliu apelează
 * `RecentlyViewed::record()` cu acel tip — sursa de adevăr e `App\Support\RecentlyViewed`.
 */
export type SearchResultType = 'account' | 'contact' | 'deal' | 'product' | 'action';

export interface SearchResult {
    type: SearchResultType;
    id: string;
    label: string;
    sublabel: string | null;
    url: string;
}

export type SearchGroupType = 'recent' | 'accounts' | 'contacts' | 'deals' | 'actions';

export interface SearchGroup {
    type: SearchGroupType;
    label: string;
    results: SearchResult[];
}

export interface SearchResponse {
    query: string;
    groups: SearchGroup[];
}


// ── Ajutor contextual — FR-HELP-01…04 ────────────────────────────────────────────


// ── Exporturi — US-CRM-03, §13.2 ─────────────────────────────────────────────────

