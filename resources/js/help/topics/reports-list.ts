import type { HelpTopic } from '@/help/types';

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
const reportsList: HelpTopic = {
    id: 'reports-list',
    title: 'Reports',
    whatIsThis:
        'Every report this workspace has defined: what each one is built from, what kind of file it produces, how often it goes out, and how its last run went. A report is a saved definition, not a one-off export.',
    whatCanYouDo: [
        'Define a new one with "New report" — or "Create your first report" while the list is empty.',
        'Read each report\'s "Source" (a saved view, or one of the two built-in reports), its "Format" (CSV, XLSX or PDF), and its "Frequency": "Manual only", "Daily", "Weekly" or "Monthly".',
        'Check "Status" for whether the schedule is "Active" or "Inactive", and "Last run" for "Queued", "Running", "Success", "Failed" — or "Never run" for one that has not gone out yet.',
        'Open any report name for its own page: "Run now", the current result, the full run history and the downloadable files.',
    ],
    rules: [
        'Owner and Manager see and manage every report here. An Agent sees only the reports where their own email address is one of the recipients — and only sees them: no "New report", no edit, no delete, no "Run now". A Viewer has no access to reports at all, not even read-only (specs.md §7.4).',
        "That narrowing is on the email address, matched without case — not on membership and not on who created the report. Recipients are plain addresses, so a report can perfectly well go to someone with no account in this workspace, and an Agent whose address was never added simply doesn't see the report exists.",
        '"Inactive" only switches off the schedule. The definition stays, its history stays, and it can still be run by hand from its own page — so switching a noisy weekly report off is not the same as deleting it.',
        'The list is not paged and has no filters, columns picker or saved views: a workspace realistically has a handful of report definitions, not thousands, so they are all here at once, sorted by name.',
        'Deleting a report is permanent and stops its scheduled delivery immediately — and it is done from the report\'s own page, not from this list, so it can\'t happen by mis-clicking a row.',
    ],
    howItsBuilt: {
        summary:
            "An Agent's narrowing is applied to the database query that builds this list, not to the rendering: the query filters the `recipients` JSON array for the viewer's own address before anything reaches the browser, so a report they are not on is never sent to their page in the first place. The same address list is normalised — trimmed, lower-cased, de-duplicated — both when it is saved and when it is read back for this check, so the two halves cannot silently drift apart and leave an Agent locked out of a report addressed to them in different capitalisation. The last-run badge comes from an eager-loaded relation rather than a query per row.",
    },
};

export default reportsList;
