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

export type AccountStatus = 'prospect' | 'active' | 'inactive';
export type CreditTerms = 'net_15' | 'net_30' | 'net_60' | 'prepaid';

export interface AccountOwnerOption {
    id: string;
    name: string;
}

export interface AccountAddress {
    line1: string | null;
    city: string | null;
    state: string | null;
    postalCode: string | null;
    country: string | null;
}

// Rândul din Accounts/Index — App\Http\Resources\Accounts\AccountResource.
export interface AccountRow {
    id: string;
    name: string;
    domain: string | null;
    industry: string | null;
    status: AccountStatus;
    owner: { id: string; name: string } | null;
    createdAt: string | null;
    // Per RÂND, nu per pagină: un Agent pe „All accounts" vede tot tenantul, dar
    // editează doar ce deține/a creat (§7.5).
    canEdit: boolean;
}

// Accounts/Show, Accounts/Edit — App\Http\Resources\Accounts\AccountDetailResource.
export interface AccountDetail {
    id: string;
    name: string;
    domain: string | null;
    industry: string | null;
    phone: string | null;
    status: AccountStatus;
    creditTerms: CreditTerms;
    source: string | null;
    tags: string[];
    billingAddress: AccountAddress | null;
    shippingAddress: AccountAddress | null;
    owner: { id: string; name: string } | null;
    createdAt: string | null;
    updatedAt: string | null;
}

// App\Http\Resources\Accounts\AccountContactResource — doar afișare pe Accounts/Show.
export interface AccountContactRow {
    id: string;
    name: string;
    email: string | null;
    phone: string | null;
    title: string | null;
    isPrimary: boolean;
}

// App\Http\Resources\Accounts\AccountDealResource — doar afișare pe Accounts/Show.
export interface AccountDealRow {
    id: string;
    title: string;
    stageName: string | null;
    value: number | null;
    currency: string;
    status: string;
    url: string;
}

// App\Support\Accounts\AccountActivityTimeline — FR-CRM-04.
export interface AccountActivityEntry {
    id: string;
    description: string;
    at: string | null;
    url: string | null;
}

export interface AccountsIndexPageProps {
    accounts: CursorPage<AccountRow>;
    list: ListState;
    owners: AccountOwnerOption[];
    can: { create: boolean; export: boolean };
    [key: string]: unknown;
}

export interface AccountsShowPageProps {
    account: AccountDetail;
    contacts: AccountContactRow[];
    deals: AccountDealRow[];
    activity: AccountActivityEntry[];
    deletionBlockedReason: string | null;
    can: { edit: boolean; delete: boolean; createDeal: boolean; createContact: boolean };
    [key: string]: unknown;
}

export interface AccountsFormPageProps {
    account?: AccountDetail;
    owners: AccountOwnerOption[];
    prefill?: { name: string };
    [key: string]: unknown;
}

// ── Contacts — FR-CRM-02 ─────────────────────────────────────────────────────────


// ── Deals și kanban — FR-DEAL-01, FR-DEAL-03 ─────────────────────────────────────


// ── Pipeline și etape — FR-DEAL-02 ───────────────────────────────────────────────


// ── Settings și preferințe — FR-PREF-01…03 ───────────────────────────────────────


// ── Căutare globală — FR-SEARCH-01 ───────────────────────────────────────────────


// ── Ajutor contextual — FR-HELP-01…04 ────────────────────────────────────────────


// ── Exporturi — US-CRM-03, §13.2 ─────────────────────────────────────────────────

export type ExportStatus = 'pending' | 'running' | 'completed' | 'cancelled' | 'failed';

// App\Http\Resources\Exports\ExportResource — Exports/Show.
export interface ExportStatusPayload {
    id: string;
    resourceType: string;
    status: ExportStatus;
    totalRows: number;
    canDownload: boolean;
}

export interface ExportsShowPageProps {
    export: ExportStatusPayload;
    [key: string]: unknown;
}

