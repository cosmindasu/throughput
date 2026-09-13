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

export type DealStatus = 'open' | 'won' | 'lost';

export type LostReason = 'price' | 'competition' | 'timing' | 'other';

// `App\Http\Resources\DealStageResource` — numit `DealStage`, nu `Stage`: configurarea
// de pipeline/etape (`/{w}/pipeline`, FR-DEAL-02) e alt pachet și își declară propriile
// tipuri fără coliziune de nume.
export interface DealStage {
    id: string;
    name: string;
    position: number;
    isWon: boolean;
    isLost: boolean;
    probability: number | null;
}

export interface DealPartyRef {
    id: string;
    name: string;
}

export interface DealStageRef {
    id: string;
    name: string;
    isWon: boolean;
    isLost: boolean;
}

// `App\Http\Resources\DealSummaryResource` — un rând din `Deals/Index` sau un card din
// `Deals/Kanban` (aceeași formă pentru amândouă). `can` e PER RÂND (US-CRM-02): un Agent
// vede toate deal-urile, dar butoanele de scriere apar doar pe ale lui.
export interface DealSummary {
    id: string;
    title: string;
    value: number | null;
    currency: string;
    expectedCloseDate: string | null;
    status: DealStatus;
    lostReason: LostReason | null;
    createdAt: string | null;
    account: DealPartyRef;
    owner: DealPartyRef;
    stage: DealStageRef;
    can: {
        edit: boolean;
        moveStage: boolean;
    };
}

// `App\Http\Resources\DealResource` — detaliul complet (`Deals/Show`, `Deals/Edit`).
export interface Deal {
    id: string;
    title: string;
    value: number | null;
    currency: string;
    expectedCloseDate: string | null;
    status: DealStatus;
    lostReason: LostReason | null;
    account: DealPartyRef;
    primaryContact: DealPartyRef | null;
    pipeline: DealPartyRef;
    stage: DealStageRef;
    owner: DealPartyRef;
    createdAt: string | null;
    updatedAt: string | null;
}

// `App\Http\Resources\DealStageEventResource` — istoricul de tranziții (§9.1).
export interface DealStageEvent {
    id: string;
    fromStage: DealPartyRef | null;
    toStage: DealPartyRef;
    changedBy: DealPartyRef | null;
    changedAt: string | null;
    durationInPreviousStageSeconds: number | null;
}

export interface DealsIndexPageProps {
    deals: CursorPage<DealSummary>;
    filters: ListState;
    can: {
        create: boolean;
    };
    [key: string]: unknown;
}

export type DealOwnerFilter = 'me' | 'all';

export interface DealsBoardColumn {
    stage: DealStage;
    deals: DealSummary[];
    total: number;
    hasMore: boolean;
}

export interface DealsKanbanPageProps {
    pipeline: DealPartyRef;
    columns: DealsBoardColumn[];
    ownerFilter: DealOwnerFilter;
    can: {
        create: boolean;
        managePipeline: boolean;
    };
    [key: string]: unknown;
}

export interface DealsShowPageProps {
    deal: Deal;
    stageEvents: DealStageEvent[];
    stages: DealStage[];
    can: {
        edit: boolean;
        delete: boolean;
        moveStage: boolean;
        changeOwner: boolean;
    };
    [key: string]: unknown;
}

export interface DealsCreatePageProps {
    account: DealPartyRef;
    contacts: DealPartyRef[];
    owners: DealPartyRef[];
    can: {
        changeOwner: boolean;
    };
    [key: string]: unknown;
}

export interface DealsEditPageProps {
    deal: Deal;
    contacts: DealPartyRef[];
    owners: DealPartyRef[];
    can: {
        changeOwner: boolean;
        delete: boolean;
    };
    [key: string]: unknown;
}


// ── Pipeline și etape — FR-DEAL-02 ───────────────────────────────────────────────


// ── Settings și preferințe — FR-PREF-01…03 ───────────────────────────────────────


// ── Căutare globală — FR-SEARCH-01 ───────────────────────────────────────────────


// ── Ajutor contextual — FR-HELP-01…04 ────────────────────────────────────────────


// ── Exporturi — US-CRM-03, §13.2 ─────────────────────────────────────────────────

