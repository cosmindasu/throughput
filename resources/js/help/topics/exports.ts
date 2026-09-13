import type { HelpTopic } from '@/help/types';

/**
 * `Exports/Show` — specs.md §7.4 nota ³, §13.1/§13.2 (mecanismul de coadă, folosit
 * aici pentru prima dată, pe export), US-CRM-03. Distinct de exportul GDPR de
 * tenant din §20.5 (Settings → „Export data", Faza 5) — acesta e statusul unui
 * export de LISTĂ (CSV filtrat), declanșat din Accounts/Contacts/Deals etc.
 *
 * Presupuneri de buton semnalate în raport: „Download file" — confirmat la
 * construirea mecanismului de export (pachet paralel, §13).
 */
const exportsTopic: HelpTopic = {
    id: 'exports',
    title: 'Export status',
    whatIsThis: "This page shows the progress of a CSV export you started from a list — it's a status page, not a place you navigate to directly.",
    whatCanYouDo: [
        'Watch the export progress if it\'s running as a background job.',
        'Download the file once it\'s ready.',
        'Come back to this page later — the link keeps working until the export expires.',
    ],
    rules: [
        'A small export (under 5,000 rows) finishes immediately, no waiting screen. A larger one runs as a background job so the request doesn\'t hang — you land here to watch it finish instead.',
        'The file always contains exactly the rows matching the filter you had applied when you clicked Export, nothing more.',
        'This is available to every role that can view the underlying list, including Viewer — exporting what\'s already visible on screen is a read, not a write.',
    ],
    howItsBuilt: {
        summary:
            "The 5,000-row threshold decides synchronous vs. queued, not the resource type — under it, the export runs inline and returns the file directly; over it, the same filter is captured and re-run in a queued job instead of holding the request open. This is the first use of that pattern in the app; a general-purpose bulk-operation engine (reassign, tag, bulk price updates) is built on the same mechanism in the following phase, applied first here on the simplest possible case: a read. No dedicated ADR — see specs.md §13.2 for the mechanism.",
    },
};

export default exportsTopic;
