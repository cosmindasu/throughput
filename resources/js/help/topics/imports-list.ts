import type { HelpTopic } from '@/help/types';

/**
 * `Imports/Index` — specs.md §14 (fluxul în 4 pași), §14.2 (tabela `imports`), §7.4 rândul
 * „Import CSV" (Owner/Manager CRUD, Agent și Viewer „—"), §22.5 (1 import activ per tenant).
 *
 * Reconciliat cu codul la 2026-09-19 (lotul N, Faza 4): `Pages/Imports/Index.tsx`
 * (etichetele de status, coloanele, „New import"/„Start your first import"),
 * `ImportController::index()` (`RECENT_IMPORTS_LIMIT` = 50, fără paginare pe cursor —
 * motivat acolo), `ImportPolicy` (fără îngustare pe proprietate: un Owner/Manager vede TOATE
 * importurile tenantului, nu doar ale lui), `ImportConcurrencyGuard` („activ" = orice status
 * NEterminal, inclusiv un import abandonat la „uploaded"), `Permissions::forRoles()` (nici
 * Agent, nici Viewer n-au `imports.view`, deci elementul lipsește și din `NAV_ITEMS`).
 */
const importsList: HelpTopic = {
    id: 'imports-list',
    title: 'Imports',
    whatIsThis:
        'Every file this workspace has brought in through the import flow: what it contained, how far it got, and how many of its rows turned out usable. You start a new import from here, and you come back here to re-open an older one.',
    whatCanYouDo: [
        'Start a file with "New import" — or "Start your first import" while the list is still empty.',
        'Read where each file stands from its status: "Uploaded", "Mapped", "Validating…", "Ready to import", "Importing…", "Completed", "Completed with errors" or "Failed".',
        'Check the "Rows" column for the "N valid / M invalid" split the dry run produced, before deciding whether the file is worth opening at all.',
        'Open a file name to go back into its four-step flow, or straight to its final report.',
        'See who brought each file in, and when, under "Uploaded by" and "Uploaded".',
    ],
    rules: [
        'Only Owner and Manager reach this screen. Agent and Viewer have no import rights whatsoever (specs.md §7.4) — "Imports" is missing from their navigation, and the route refuses them as well, so the menu is not the only thing protecting it.',
        'There is no "my imports" narrowing here, unlike Accounts, Deals or Orders: an Owner or Manager sees every import of the workspace, whoever uploaded it. The permission matrix gives Agent no access at all, so there is no "own" subset to carve out.',
        'One import can be active per workspace at a time. "Active" is anything that has not reached "Completed", "Completed with errors" or "Failed" — including a file uploaded and then abandoned before mapping, which keeps blocking the next upload until it is finished.',
        'The list shows the 50 most recent imports and stops there. An import is a deliberate, occasional action, not a stream of rows, so it does not get the cursor paging the big lists use.',
        'Nothing is deleted from here, and there is no button to try: the row, the uploaded file and the raw content of every failed row are all kept. That is exactly what makes the downloadable, re-importable error report possible afterwards (BR-IMP-01).',
    ],
    howItsBuilt: {
        summary:
            'Each row is an `imports` record whose counters are written by the background jobs, not by this page: every chunk of 500 rows commits `total_rows`/`valid_rows`/`error_rows` in its own short transaction, so the "N valid / M invalid" split here is accurate even while the file is still being processed — it is a running total, not a figure written once at the end. The one-active-import rule is enforced server-side in the upload action, against the set of non-terminal statuses, not by hiding the button: a second upload gets a clear refusal rather than being silently queued behind the first. The list itself deliberately skips the cursor-paging, saved-views and bulk machinery the volume lists share — 50 rows ordered newest-first is the whole query.',
    },
};

export default importsList;
