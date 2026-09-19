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
    // `null` când rolul nu citește jurnalul de activitate (Viewer, specs §7.4).
    activity: ActivityItem[] | null;
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
    // Pachetul C („bulk"), §13.1 — numărul EXACT de rânduri pe care le-ar ATINGE
    // operația bulk pe filtrul curent (`App\Support\Bulk\BulkMatchingRowCount`, P2-003),
    // NU al filtrului brut: pentru un Agent (BR-BULK-02), restricția de proprietate e
    // deja aplicată aici, identic cu `DispatchBulkOperationAction`. Deferred, ca
    // `accounts`: un al doilea COUNT pe același filtru, nu blochează randarea rândurilor.
    total: number;
    list: ListState;
    // Selector de coloane (specs.md §15.1) — coloanele EFECTIVE (validate server-side prin
    // `App\Support\SavedViews\ListColumns`), în ordinea lor de afișare; NU în `ListState`
    // (folosit și de liste fără selector, ex. Contacts) — un prop separat, la fel ca `total`.
    columns: string[];
    owners: AccountOwnerOption[];
    can: { create: boolean; export: boolean; bulkWrite: boolean };
    // App\Support\Bulk\BulkConfirmationThreshold — FR-BULK-01 (125 pentru Agent, 1.000
    // pentru Owner/Manager) și BR-BULK-02 (`null` = fără plafon de rol).
    bulkConfirmationThreshold: number;
    bulkRowCap: number | null;
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
    // `ContactResource::toArray()` folosește `whenLoaded('account', ...)` — cheia
    // LIPSEȘTE din JSON (nu `null`) când relația n-a fost încărcată (code review P3-d).
    account?: ContactAccountSummary | null;
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
        export: boolean;
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

// `primaryContact` al lui `Deal` — distinct de `DealPartyRef`: un contact anonimizat
// (§20.5) rămâne legat de deal pentru integritatea istoricului, dar `isAnonymized`
// spune interfeței să-l arate ca text neutru, fără link (`Deals/Show.tsx`) și ca
// opțiune informativă, nu aleasă din nou, în select-ul de pe `Deals/Edit.tsx`.
export interface DealContactRef {
    id: string;
    name: string;
    isAnonymized: boolean;
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
    primaryContact: DealContactRef | null;
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
    // Pachetul C („bulk"), §13.1 — vezi `AccountsIndexPageProps.total`, aceeași formă.
    total: number;
    filters: ListState;
    // Selector de coloane (specs.md §15.1) — vezi nota din `AccountsIndexPageProps.columns`.
    columns: string[];
    can: {
        create: boolean;
        bulkWrite: boolean;
    };
    // Gol când `can.bulkWrite` e fals — ca la `Deals/Create`/`Deals/Edit`
    // (`Gate::allows('bulkReassignOwner', Deal::class)`), nu o listă needed dar netrimisă.
    owners: DealPartyRef[];
    bulkConfirmationThreshold: number;
    bulkRowCap: number | null;
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
    // `null` fără `?account=` (§9 task): câmpul „Account" pornește gol în
    // `AccountCombobox`, nu mai există `findOrFail` pe un query param absent.
    account: DealPartyRef | null;
    contacts: DealPartyRef[];
    owners: DealPartyRef[];
    can: {
        changeOwner: boolean;
    };
    [key: string]: unknown;
}

export interface DealsEditPageProps {
    deal: Deal;
    // Contul REZOLVAT de `DealController::edit()` (code review P2-002) — sursă unică
    // pentru combobox ȘI `contacts`: din `?account=` dacă e prezent (inclusiv gol →
    // `null`, cazul „Clear"), altfel contul curent al deal-ului. NU e mereu egal cu
    // `deal.account` (care rămâne contul SALVAT, neschimbat până la submit).
    account: DealPartyRef | null;
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

// ── Produse și stoc — specs.md §10, FR-STOCK-01…03, BR-STOCK-01…04 ───────────────

export type UnitOfMeasure = 'each' | 'box' | 'pallet';

// App\Http\Resources\Products\ProductResource — rândul din Products/Index.
export interface ProductRow {
    id: string;
    name: string;
    category: string | null;
    unitOfMeasure: UnitOfMeasure;
    isActive: boolean;
    variantsCount: number;
    // FR-STOCK-02 — subinterogare agregată în ProductList::baseQuery(), nu un withCount
    // separat (App\Support\Stock\LowStockRule). Afișarea pe listă e a lotului D.
    lowStockVariantsCount: number;
    createdAt: string | null;
}

// App\Http\Resources\Products\VariantResource — `cost`/`onHand`/`reserved`/`available`/
// `isLowStock` sunt câmpuri OPȚIONALE, nu `| null`: lipsesc din JSON (nu apar deloc), nu
// sunt `null`. `cost` lipsește pentru Agent/Viewer (§7.4, `Permissions::canViewCost()`);
// celelalte patru lipsesc când `inventoryLevels` n-a fost încărcată pe rândul cerut.
// `lowStockThreshold` (FR-STOCK-02) e mereu prezent — nu e sensibil ca `cost`.
export interface VariantRow {
    id: string;
    productId: string;
    sku: string;
    attributes: Record<string, unknown>;
    price: number;
    cost?: number;
    weight: number | null;
    isActive: boolean;
    onHand?: number;
    reserved?: number;
    available?: number;
    lowStockThreshold: number | null;
    isLowStock?: boolean;
    createdAt: string | null;
}

// App\Http\Resources\Products\ProductDetailResource — Products/Show, Products/Edit.
export interface ProductDetail {
    id: string;
    name: string;
    category: string | null;
    unitOfMeasure: UnitOfMeasure;
    isActive: boolean;
    variants: VariantRow[];
    createdAt: string | null;
    updatedAt: string | null;
}

export interface ProductsIndexPageProps {
    products: CursorPage<ProductRow>;
    // Pachetul C („bulk"), lotul E — vezi `AccountsIndexPageProps.total`, aceeași formă.
    total: number;
    list: ListState;
    // Selector de coloane (specs.md §15.1) — vezi nota din `AccountsIndexPageProps.columns`.
    columns: string[];
    // `bulkWrite` acoperă atât prețul în masă, cât și activarea/dezactivarea — un singur
    // drept (`ProductPolicy::bulkWrite()`, Owner/Manager).
    can: { create: boolean; bulkWrite: boolean };
    bulkConfirmationThreshold: number;
    bulkRowCap: number | null;
    [key: string]: unknown;
}

export interface ProductsShowPageProps {
    product: ProductDetail;
    deletionBlockedReason: string | null;
    can: { edit: boolean; delete: boolean; createVariant: boolean };
    [key: string]: unknown;
}

export interface ProductsFormPageProps {
    product?: ProductDetail;
    [key: string]: unknown;
}

export interface VariantsCreatePageProps {
    product: ProductDetail;
    [key: string]: unknown;
}

export interface VariantsEditPageProps {
    variant: VariantRow;
    product: { id: string; name: string };
    [key: string]: unknown;
}

// App\Http\Resources\Stock\StockLevelResource — Stock/Show.
export interface StockLevelRow {
    id: string;
    locationId: string;
    locationName: string;
    onHand: number;
    reserved: number;
    available: number;
    updatedAt: string | null;
}

export interface StockLocationOption {
    id: string;
    name: string;
}

export interface StockShowPageProps {
    variant: VariantRow;
    levels: StockLevelRow[];
    locations: StockLocationOption[];
    can: { adjust: boolean };
    [key: string]: unknown;
}

export type StockMovementReason = 'receipt' | 'sale' | 'adjustment' | 'return' | 'transfer';

// App\Http\Resources\Stock\StockMovementResource — Stock/History.
export interface StockMovementRow {
    id: string;
    delta: number;
    reason: StockMovementReason;
    refType: string | null;
    refId: string | null;
    note: string | null;
    location: { id: string; name: string };
    createdBy: { id: string; name: string } | null;
    createdAt: string | null;
}

export interface StockHistoryPageProps {
    variant: { id: string; sku: string; productName: string };
    movements: CursorPage<StockMovementRow>;
    list: ListState;
    reasons: StockMovementReason[];
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
    sentEmails: boolean;
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

// Settings/SentEmails/Index — App\Http\Resources\Settings\SentEmailResource, BR-DEMO-02,
// specs.md §22.3. Un rând per email văzut de `App\Mail\Transport\DemoInterceptingTransport`.
export type SentEmailStatus = 'delivered' | 'intercepted' | 'partial' | 'failed';

export interface SentEmailRecipient {
    type: 'to' | 'cc' | 'bcc';
    address: string;
    name: string | null;
    allowed: boolean;
}

export interface SentEmailRow {
    id: string;
    mailer: string;
    status: SentEmailStatus;
    subject: string;
    fromAddress: string | null;
    fromName: string | null;
    recipients: SentEmailRecipient[];
    // Corp REDACTAT la scriere (App\Support\Mail\SentEmailRedactor) — niciodată brut.
    htmlBody: string | null;
    textBody: string | null;
    redacted: boolean;
    createdAt: string;
}

export interface SentEmailsIndexPageProps {
    sentEmails: CursorPage<SentEmailRow>;
    list: ListState;
    statuses: SentEmailStatus[];
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
    // Selector de coloane (specs.md §15.1) — deja sanitizate față de lista permisă curentă
    // (`SavedViewResource::toArray()`), în ordinea lor de afișare.
    columns: string[];
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
    // §13.5 (decizie DomPDF, Orders) — 'csv' implicit pentru exporturile scrise înainte de
    // acest câmp (Accounts/Contacts, valul 1).
    format: 'csv' | 'pdf';
    status: ExportStatus;
    totalRows: number;
    canDownload: boolean;
    // FR-GDPR-01, specs.md §20.5 — null cât timp exportul nu e `completed`.
    expiresAt: string | null;
    isExpired: boolean;
}

export interface ExportsShowPageProps {
    export: ExportStatusPayload;
    [key: string]: unknown;
}

// ── Operații în masă de SCRIERE — specs.md §13.2, plan §9 „Operații în masă" ─────

export type BulkOperationStatus = 'pending' | 'running' | 'completed' | 'cancelled' | 'failed';

// App\Http\Resources\Bulk\BulkOperationResource — Bulk/Show.
export interface BulkOperationPayload {
    id: string;
    resourceType: string;
    action: string;
    status: BulkOperationStatus;
    totalRows: number;
    totalJobs: number;
    processedJobs: number;
    failedJobs: number;
    // Aproximare (`total_rows × processedJobs / totalJobs`) — exactă la 0% și 100%, vezi
    // docblock-ul `BulkOperationResource` pentru motiv (schema n-are un contor de rânduri).
    processedRowsEstimate: number;
    canCancel: boolean;
    errorMessage: string | null;
}

export interface BulkShowPageProps {
    operation: BulkOperationPayload;
    [key: string]: unknown;
}

// ── Comenzi — specs.md §11, FR-ORD-02…07, plan §9 task 1 ─────────────────────────

export type OrderStatus = 'draft' | 'confirmed' | 'partially_fulfilled' | 'fulfilled' | 'cancelled';

export interface OrderPartyRef {
    id: string;
    name: string;
}

// App\Http\Resources\Orders\OrderLineResource.
export interface OrderLine {
    id: string;
    variantId: string;
    sku: string | null;
    description: string;
    quantity: number;
    unitPrice: number;
    discount: number;
    lineTotal: number;
    quantityFulfilled: number;
    // Faza 3, valul 2 (§11.2 pas 4) — `quantity − quantityFulfilled − shipment-urile
    // DESCHISE încă neexpediate` (App\Support\Orders\RemainingToShip). `null` doar dacă
    // resource-ul a fost randat fără liniile de shipment încărcate (nu se întâmplă pe
    // `Orders/Show` — controllerul le încarcă mereu).
    remainingToShip: number | null;
}

// ── Onorare/expediere — specs.md §11.2 pași 4-6, US-ORD-02/03, plan §9 valul 2 ───

export type ShipmentStatus = 'label_pending' | 'label_failed' | 'label_purchased' | 'in_transit' | 'delivered' | 'exception';

// App\Http\Resources\Orders\ShipmentLineResource.
export interface ShipmentLine {
    id: string;
    orderLineId: string;
    description: string | null;
    quantity: number;
}

// App\Http\Resources\Orders\ShipmentResource.
export interface Shipment {
    id: string;
    status: ShipmentStatus;
    carrier: string;
    serviceLevel: string | null;
    trackingNumber: string | null;
    labelUrl: string | null;
    // `null` pentru un furnizor fără adaptor încă (`shippo`, Faza 5) — vezi docblock-ul
    // `ShipmentResource`. UI-ul pur și simplu nu randează linkul când e `null`.
    trackingUrl: string | null;
    errorMessage: string | null;
    shippedAt: string | null;
    deliveredAt: string | null;
    cost: number | null;
    createdAt: string | null;
    lines: ShipmentLine[];
    can: {
        retryLabel: boolean;
        discard: boolean;
        markShipped: boolean;
    };
}

// App\Http\Resources\Orders\OrderResource — detaliul complet (`Orders/Show`, `Orders/Edit`).
export interface Order {
    id: string;
    orderNumber: string | null;
    status: OrderStatus;
    statusLabel: string;
    currency: string;
    subtotal: number;
    discountTotal: number;
    shippingTotal: number;
    grandTotal: number;
    notes: string | null;
    placedAt: string | null;
    createdAt: string | null;
    updatedAt: string | null;
    account: OrderPartyRef;
    // §20.5 — un contact anonimizat rămâne vizibil aici (istoric), exact ca pe Deal.
    contact: { id: string; name: string; isAnonymized: boolean } | null;
    deal: { id: string; title: string } | null;
    owner: OrderPartyRef;
    lines: OrderLine[];
    // Faza 3, valul 2 — cele mai recente primele (`ORDER BY created_at DESC`, vezi
    // `OrderController::show()`).
    shipments: Shipment[];
}

// App\Http\Resources\Orders\OrderSummaryResource — un rând din `Orders/Index`.
export interface OrderSummary {
    id: string;
    orderNumber: string | null;
    status: OrderStatus;
    statusLabel: string;
    currency: string;
    grandTotal: number;
    placedAt: string | null;
    createdAt: string | null;
    account: OrderPartyRef;
    owner: OrderPartyRef;
    can: {
        edit: boolean;
        cancel: boolean;
    };
}

export interface OrdersIndexPageProps {
    orders: CursorPage<OrderSummary>;
    // Pachetul C („bulk"), lotul E — vezi `AccountsIndexPageProps.total`, aceeași formă:
    // N-ul EXACT pe care REASIGNAREA l-ar atinge pe filtrul curent.
    total: number;
    // Distinct de `total`: N-ul EXACT pe care ANULAREA ÎN MASĂ (doar `draft`, §13.5) l-ar
    // atinge — nu tot filtrul. Vezi `App\Support\Bulk\BulkChunkActions::narrowQuery()`.
    draftTotal: number;
    filters: ListState;
    // Selector de coloane (specs.md §15.1) — vezi nota din `AccountsIndexPageProps.columns`.
    columns: string[];
    can: {
        create: boolean;
        export: boolean;
        bulkReassignOwner: boolean;
        bulkCancelDrafts: boolean;
    };
    // Gol când `can.bulkReassignOwner` e fals.
    owners: OrderPartyRef[];
    bulkConfirmationThreshold: number;
    bulkRowCap: number | null;
    [key: string]: unknown;
}

export interface OrdersShowPageProps {
    order: Order;
    can: {
        edit: boolean;
        delete: boolean;
        confirm: boolean;
        cancel: boolean;
        // Faza 3, valul 2 — `Gate::allows('create', [Shipment::class, $order])`. Per-shipment
        // `can` (retryLabel/discard/markShipped) vine pe fiecare `Shipment`, nu aici — depinde
        // de shipment, nu doar de comandă.
        createShipment: boolean;
    };
    [key: string]: unknown;
}

export interface OrdersCreatePageProps {
    account: OrderPartyRef | null;
    contacts: OrderPartyRef[];
    owners: OrderPartyRef[];
    can: {
        changeOwner: boolean;
    };
    [key: string]: unknown;
}

export interface OrdersEditPageProps {
    order: Order;
    account: OrderPartyRef | null;
    contacts: OrderPartyRef[];
    owners: OrderPartyRef[];
    can: {
        changeOwner: boolean;
        delete: boolean;
    };
    [key: string]: unknown;
}

// `GET /{workspace}/orders/variants/lookup` — App\Http\Controllers\Web\Orders\VariantLookupController.
// JSON simplu (nu props Inertia), consumat de `VariantCombobox` din `Orders/Create`/`Orders/Edit`.
export interface OrderVariantOption {
    id: string;
    sku: string;
    name: string;
    price: number;
    available: number;
}

// ── Membri și roluri — specs.md §6.4/§6.4.1, US-TEN-02/03, ADR-011 ──────────────

export type MembershipStatus = 'active' | 'pending' | 'deactivated';

// App\Http\Resources\Members\MembershipResource — BR-TEN-06, numărul exact afișat în
// confirmarea de dezactivare.
export interface MembershipOpenRecords {
    deals: number;
    orders: number;
    total: number;
}

export interface MembershipRow {
    id: string;
    user: { id: string; name: string; email: string };
    role: string | null;
    status: MembershipStatus;
    joinedAt: string | null;
    deactivatedAt: string | null;
    deactivatedBy: { id: string; name: string } | null;
    openRecords: MembershipOpenRecords;
    canDeactivate: boolean;
    // BR-TEN-01 — „blocare server-side, NU doar ascunsă în UI": dialogul de confirmare
    // arată mesajul de blocare, fără butoane de acțiune, în loc să ascundă butonul.
    isLastActiveOwner: boolean;
}

export interface MembersIndexPageProps {
    members: MembershipRow[];
    activeMembers: Array<{ id: string; name: string }>;
    can: {
        invite: boolean;
    };
    [key: string]: unknown;
}

// ── Unassigned — specs.md §6.4.1, FR-TEN-05 ──────────────────────────────────────
// NU conturi — DECIZIE deja luată (vezi raportul pachetului „Membri și roluri"): deals
// deschise + comenzi active ale membrilor dezactivați, literal după Gherkin-ul US-TEN-03.

export interface UnassignedIndexPageProps {
    deals: CursorPage<DealSummary>;
    orders: CursorPage<OrderSummary>;
    can: {
        reassign: boolean;
    };
    activeMembers: Array<{ id: string; name: string }>;
    [key: string]: unknown;
}

// ── Progres agregat pe `group_id` — specs.md §13.2, BR-BULK-04 ──────────────────
// App\Http\Resources\Bulk\BulkOperationGroupResource — Bulk/Groups/Show. Singurul caz din
// MVP: reatribuirea la dezactivarea unui membru (US-TEN-03), trei `BulkOperationPayload`
// (conturi/deals/comenzi) sub același `groupId`.

export interface BulkOperationGroupPayload {
    groupId: string;
    status: BulkOperationStatus;
    totalRows: number;
    operations: BulkOperationPayload[];
    canCancel: boolean;
}

export interface BulkGroupShowPageProps {
    group: BulkOperationGroupPayload;
    [key: string]: unknown;
}

// ── Rapoarte și livrare programată — specs.md §16, plan §10, lotul K din Faza 4 ──
// Mirror manual al `App\Http\Resources\Reports\ReportDefinitionResource`/`ReportRunResource`.

export type ReportFormat = 'csv' | 'xlsx' | 'pdf';
export type ReportScheduleFrequency = 'none' | 'daily' | 'weekly' | 'monthly';
export type ReportRunStatus = 'queued' | 'running' | 'success' | 'failed';
export type ReportTriggeredBy = 'scheduler' | 'manual';

export interface ReportSavedViewRef {
    id: string;
    name: string;
    resourceType: string;
}

// App\Http\Resources\Reports\ReportRunResource — istoricul de rulări (FR-REP-01).
export interface ReportRunRow {
    id: string;
    status: ReportRunStatus;
    triggeredBy: ReportTriggeredBy;
    startedAt: string | null;
    finishedAt: string | null;
    rowCount: number | null;
    errorMessage: string | null;
    hasFile: boolean;
}

// App\Http\Resources\Reports\ReportDefinitionResource — Reports/Index și Reports/Show.
export interface ReportDefinitionRow {
    id: string;
    name: string;
    reportType: string;
    isBuiltIn: boolean;
    sourceLabel: string;
    savedView: ReportSavedViewRef | null;
    format: ReportFormat;
    scheduleFrequency: ReportScheduleFrequency;
    scheduleTime: string | null;
    scheduleDay: number | null;
    recipients: string[];
    isActive: boolean;
    createdBy: string | null;
    lastRun: ReportRunRow | null;
    canUpdate: boolean;
    canDelete: boolean;
    canRunNow: boolean;
}

export interface ReportBuiltInOption {
    value: string;
    label: string;
}

export interface ReportsIndexPageProps {
    reports: ReportDefinitionRow[];
    can: {
        create: boolean;
    };
    [key: string]: unknown;
}

export interface ReportsCreatePageProps {
    savedViews: SavedViewSummary[];
    builtInReports: ReportBuiltInOption[];
    [key: string]: unknown;
}

export interface ReportsEditPageProps {
    report: ReportDefinitionRow;
    savedViews: SavedViewSummary[];
    builtInReports: ReportBuiltInOption[];
    [key: string]: unknown;
}

// App\Http\Controllers\Web\Reports\ReportController::builtInPreview() — US-REP-02,
// rezultatul sincron randat direct în `Reports/Show`, plafonat (vezi docblock-ul PHP).
export interface ReportBuiltInPreview {
    columns: string[];
    rows: Array<Array<string | number | null>>;
    totalRows: number;
    truncated: boolean;
    // Fix P1 (review) — `true` când raportul expune date derivate din `variants.cost` și
    // utilizatorul curent n-are `Permissions::canViewCost()` (Agent/Viewer destinatar).
    // `columns`/`rows` sunt goale în acel caz — randează un motiv, nu un tabel gol.
    hiddenForCost: boolean;
}

export interface ReportsShowPageProps {
    report: ReportDefinitionRow;
    runs: ReportRunRow[];
    builtInPreview: ReportBuiltInPreview | null;
    can: {
        update: boolean;
        delete: boolean;
        runNow: boolean;
        download: boolean;
    };
    [key: string]: unknown;
}

// ── Import CSV — specs.md §14, plan §10 ──────────────────────────────────────────
// Flux în 4 pași (Upload → Mapare → Probă uscată → Commit → Raport), un singur ecran
// (`Imports/Show`) cu stări succesive pe `import.status`.

export type ImportStatus =
    | 'uploaded'
    | 'mapped'
    | 'validating'
    | 'validated'
    | 'importing'
    | 'completed'
    | 'completed_with_errors'
    | 'failed';

export type ImportResourceType = 'accounts' | 'contacts' | 'products' | 'variants';

// App\Http\Resources\Imports\ImportResource.
export interface ImportSummary {
    id: string;
    resourceType: ImportResourceType;
    resourceLabel: string;
    originalFilename: string;
    status: ImportStatus;
    totalRows: number | null;
    validRows: number | null;
    errorRows: number | null;
    columnMapping: Record<string, string | null> | null;
    createdBy?: { id: string; name: string };
    createdAt: string | null;
    completedAt: string | null;
}

export interface ImportsIndexPageProps {
    imports: ImportSummary[];
    can: {
        create: boolean;
    };
    [key: string]: unknown;
}

export interface ImportResourceOption {
    value: ImportResourceType;
    label: string;
}

export interface ImportsCreatePageProps {
    resources: ImportResourceOption[];
    limits: {
        maxFileMb: number;
        maxRows: number;
    };
    [key: string]: unknown;
}

// Un câmp țintă al resursei (`App\Support\Imports\ImportField`), pentru ecranul de mapare.
export interface ImportFieldDefinition {
    key: string;
    label: string;
    required: boolean;
}

export type ImportMappingConfidence = 'high' | 'medium' | 'low' | 'none';

// App\Support\Imports\ColumnMappingSuggester::suggest() — US-IMP-02, un indicator de
// încredere per coloană a fișierului încărcat.
export interface ImportMappingSuggestion {
    header: string;
    field: string | null;
    confidence: ImportMappingConfidence;
    score: number;
}

// App\Http\Resources\Imports\ImportRowResource — tabelul de erori al probei uscate.
export interface ImportRowError {
    rowNumber: number;
    errors: Array<{ field: string; message: string }>;
}

export interface ImportsShowPageProps {
    import: ImportSummary;
    fields: ImportFieldDefinition[];
    // Prezente DOAR cât timp `import.status === 'uploaded'` (Pasul 2, mapare).
    headers: string[] | null;
    mappingSuggestions: ImportMappingSuggestion[] | null;
    // Prezente din `validating` încolo — `null` înainte de prima probă uscată.
    invalidRows: ImportRowError[] | null;
    invalidRowsTruncated: boolean;
    templateUrl: string;
    errorReportUrl: string | null;
    can: {
        manage: boolean;
        cancel: boolean;
    };
    [key: string]: unknown;
}

