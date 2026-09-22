import type { HelpTopicDefinition } from '@/help/types';

/**
 * `Reports/Index` — specs.md §16 („lista rapoartelor: nume, sursă, format, frecvență, activ,
 * ultima rulare"), §16.1 (`report_definitions`), §7.4 rândul „Rapoarte programate"
 * (Owner/Manager CRUD, Agent „R doar cele unde e destinatar", Viewer „—").
 *
 * Reconciliat cu codul la 2026-09-19 (lotul N, Faza 4): `Pages/Reports/Index.tsx`
 * (coloanele Name/Source/Format/Frequency/Status/Last run, „New report"/„Create your first
 * report", cele două mesaje de listă goală — al doilea e cel văzut de un Agent),
 * `ReportController::index()` (fără paginare, `orderBy('name')`),
 * `ReportRecipients::scopeVisibleTo()` (îngustarea ABAC aplicată în INTEROGARE, nu doar pe
 * pagina de detaliu, cu `whereJsonContains` pe array-ul `recipients`; comparație
 * case-insensitive, normalizată și la scriere, și la citire), `ReportDefinitionPolicy`
 * (`reports.view` la citire, `reports.manage` la scriere și la „Run now"),
 * `Permissions::forRoles()` (Viewer n-are nici `reports.view`, nici `reports.manage`).
 */
const reportsList: HelpTopicDefinition = {
    id: 'reports-list',
};

export default reportsList;
