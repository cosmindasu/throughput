import { adrUrl } from '@/help/adr';
import type { HelpTopic } from '@/help/types';

/**
 * `Dashboard` — specs.md §21.3 (FR-DEMO-01), app/Http/Controllers/Web/DashboardController.php.
 * Nu e în `NAV_ITEMS` (nu e un modul cu link în bara principală), dar e prima pagină
 * după login — inclusă manual în testul de acoperire (FR-HELP-04).
 */
const dashboard: HelpTopic = {
    id: 'dashboard',
    title: 'Dashboard',
    whatIsThis:
        "This is the first screen you land on after opening a workspace — a snapshot of how the business is doing right now. It doesn't let you edit anything; it's where you start before heading into a module.",
    whatCanYouDo: [
        'Scan the four KPI tiles: open pipeline value, orders this month, overdue invoices, low stock alerts.',
        'Review the 10 most recent entries in the activity feed.',
        'Switch to another workspace from the switcher next to the Throughput logo.',
        'Open any module from the top navigation.',
    ],
    rules: [
        "Every number on this page belongs to the workspace you're currently in — switch workspaces and all four tiles change, nothing carries over from the previous one.",
        '"Low stock alerts" counts variants where the available quantity (on hand minus reserved) is 5 units or fewer.',
        '"Overdue invoices" shows the balance still owed on those invoices, not their original total.',
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
