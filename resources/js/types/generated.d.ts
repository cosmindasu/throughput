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

export interface ContactAccountSummary {
    id: string;
    name: string;
}

export interface ContactDealSummary {
    id: string;
    title: string;
    status: string;
    value: number | null;
}

export interface Contact {
    id: string;
    firstName: string;
    lastName: string;
    fullName: string;
    email: string | null;
    phone: string | null;
    title: string | null;
    isPrimary: boolean;
    optOut: boolean;
    accountId: string | null;
    account: ContactAccountSummary | null;
    deals?: ContactDealSummary[];
    createdAt: string | null;
    updatedAt: string | null;
    can: {
        edit: boolean;
        delete: boolean;
    };
}

export interface ContactsIndexPageProps {
    contacts: CursorPage<Contact>;
    list: ListState;
    can: {
        create: boolean;
    };
    [key: string]: unknown;
}

export interface ContactsShowPageProps {
    contact: Contact;
    can: {
        edit: boolean;
        delete: boolean;
    };
    [key: string]: unknown;
}

export interface ContactsCreatePageProps {
    account: ContactAccountSummary | null;
    [key: string]: unknown;
}

export interface ContactsEditPageProps {
    contact: Contact;
    [key: string]: unknown;
}


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


// ── Vizualizări salvate — specs.md §15, FR-VIEW-01…02 ────────────────────────────
// Mirror manual al `App\Http\Resources\SavedViews\SavedViewResource` — consumat de
// `SavedViewPicker.tsx` via `resources/js/lib/api.ts` (JSON simplu, ca `SearchResponse`
// mai jos, nu props Inertia: meniul de vederi nu are nevoie ca `Accounts/Index` sau
// `Deals/Index` să se re-randeze doar pentru ce a schimbat el).

export type SavedViewVisibility = 'private' | 'team';

export interface SavedViewSummary {
    id: string;
    name: string;
    resourceType: string;
    visibility: SavedViewVisibility;
    filter: Record<string, string>;
    sort: string;
    canUpdate: boolean;
    canDelete: boolean;
}

// `GET /{workspace}/saved-views/{resourceType}` — SavedViewController::index().
export interface SavedViewsIndexResponse {
    mine: SavedViewSummary[];
    team: SavedViewSummary[];
    defaultId: string | null;
    can: {
        createTeam: boolean;
    };
}

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

