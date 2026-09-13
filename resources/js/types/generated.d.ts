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

// App\Http\Resources\StageResource. `canDelete` reflectă STRICT `pipelines.manage` (dreptul,
// §1.2 regula 2) — `deletionBlockedReason` e o STARE (BR-DEAL-01, §7.5): butonul „Delete"
// rămâne prezent cât timp userul are dreptul, iar motivul explică refuzul, nu-l ascunde.
export interface PipelineStage {
    id: string;
    name: string;
    position: number;
    isWon: boolean;
    isLost: boolean;
    probability: number | null;
    dealsCount: number;
    canDelete: boolean;
    deletionBlockedReason: string | null;
}

// Pipeline/Index — FR-DEAL-02.
export interface PipelinePageProps {
    pipelineName: string;
    stages: PipelineStage[];
    can: {
        manage: boolean;
    };
    [key: string]: unknown;
}

// ── Settings și preferințe — FR-PREF-01…03 ───────────────────────────────────────

// App\Http\Controllers\Web\Settings\SettingsController::index() — plan §7.4. O secțiune
// fără drept LIPSEȘTE din interfață (§7.3, FR-RBAC-01); `preferences` e mereu `true`
// (BR-PREF-02), nu omisă, ca forma să rămână uniformă.
export interface SettingsSectionPermissions {
    members: boolean;
    billing: boolean;
    apiTokens: boolean;
    pipeline: boolean;
    preferences: boolean;
}

export interface SettingsIndexPageProps {
    can: SettingsSectionPermissions;
    [key: string]: unknown;
}

// Settings/Preferences — rândul de temă vine din props comune (`auth.user.theme`,
// `theme`), nimic specific paginii încă.
export interface SettingsPreferencesPageProps {
    [key: string]: unknown;
}


// ── Căutare globală — FR-SEARCH-01 ───────────────────────────────────────────────


// ── Ajutor contextual — FR-HELP-01…04 ────────────────────────────────────────────


// ── Exporturi — US-CRM-03, §13.2 ─────────────────────────────────────────────────

