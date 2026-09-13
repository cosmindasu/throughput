import { adrUrl } from '@/help/adr';
import type { HelpTopic } from '@/help/types';

/**
 * `Dashboard` — specs.md §21.3 (FR-DEMO-01), app/Http/Controllers/Web/DashboardController.php.
 * Nu e în `NAV_ITEMS` (nu e un modul cu link în bara principală), dar e prima pagină
 * după login — inclusă manual în testul de acoperire (FR-HELP-04).
 *
 * Reconciliat cu codul la 2026-09-13: etichetele din `Pages/Dashboard.tsx`, agregatele din
 * `DashboardController::show()` (`inventory_levels` e per variantă × locație), comutatorul
 * din `WorkspaceSwitcher.tsx` și scurtăturile reale din `GlobalSearch.tsx` (Cmd/Ctrl+K,
 * ambele modificatoare pe orice platformă) și `HelpPanel.tsx` (`?` în afara câmpurilor de
 * text, `Esc`).
 */
const dashboard: HelpTopic = {
    id: 'dashboard',
    title: 'Dashboard',
    whatIsThis:
        "This is the first screen you land on after opening a workspace — a snapshot of how the business is doing right now. It doesn't let you edit anything; it's where you start before heading into a module.",
    whatCanYouDo: [
        'Scan the four KPI tiles: "Open pipeline value", "Orders this month", "Overdue invoices" and "Low stock alerts".',
        'Review "Recent activity": the 10 most recent entries in the workspace activity log.',
        'Switch to another workspace from the switcher showing the current workspace name, next to "Throughput" in the top bar.',
        'Press Cmd+K or Ctrl+K — or click "Search" in the top bar — to jump to an account, contact or deal, or to start "Create account", "Create contact" or "Create deal".',
        'Press ? anywhere outside a text field to open this help panel; Esc closes it.',
    ],
    rules: [
        "Every number on this page belongs to the workspace you're currently in — switch workspaces and all four tiles change, nothing carries over from the previous one.",
        '"Open pipeline value" adds up the value of open deals only; won and lost deals are left out, and so are open deals with no value yet.',
        '"Low stock alerts" counts stock levels — one variant at one location — where on hand minus reserved is 5 units or fewer, so a variant running low at two locations counts twice.',
        '"Overdue invoices" shows how many invoices are overdue; the amount under the number is the balance still owed on them, not their original total.',
        'The KPI tiles and the activity feed are the same for every role — Dashboard has no per-role filtering, unlike most lists in this app.',
    ],
    howItsBuilt: {
        summary:
            "The four KPIs are plain SQL aggregates (SUM/COUNT), computed on every request — there's no cached snapshot to go stale. Notably, the controller never writes `where tenant_id`: the tenant global scope and PostgreSQL row-level security apply automatically to every query on every model, so isolation isn't something each screen has to remember to do.",
        adr: {
            id: 'ADR-003',
            title: 'Tenant isolation in two layers — global scope + Row-Level Security',
            url: adrUrl('ADR-003', 'izolare-tenant-doua-straturi'),
        },
    },
};

export default dashboard;
