import { adrUrl } from '@/help/adr';
import type { HelpTopic } from '@/help/types';

/**
 * `Settings/WebhookHealth/Index` — specs.md §25.2 („ecran «Webhook health», intern") și
 * criteriul de acceptanță din §12.3 („`error_message` populat, vizibil într-un ecran de
 * operare").
 *
 * Scris odată cu ecranul (Faza 5, valul 2). Linia de hartă din `resources/js/help/index.ts`
 * e un fișier de integrare, neatins de acest lot — vezi raportul.
 */
const webhookHealth: HelpTopic = {
    id: 'webhook-health',
    title: 'Webhook health',
    whatIsThis:
        'The operational view of everything Stripe has told this deployment: one row per event, with what happened to it. It exists so that a payment problem is visible here, in plain words, instead of only in a log file nobody reads.',
    whatCanYouDo: [
        'See the five counters at the top — how many events were received, are being processed, were processed, failed, or were ignored.',
        'Filter the list by status, to look at just the failures.',
        'Read the detail column, which says in words why an event ended where it did.',
    ],
    rules: [
        'Only Owner can open this page — it is the same right as the Billing & Subscription page, because these are the same Stripe events seen from the other side.',
        '"Failed" is the only status that needs a human. It means the signature was valid, the event was ours, and applying it did not work after three attempts.',
        '"Ignored" is not a failure. This deployment shares its Stripe sandbox with another project, so events belonging to that project arrive here with a perfectly valid signature. Nothing maps them to a workspace, nothing is applied, and the row says so.',
        'Stripe delivers each event at least once, never exactly once. A repeated delivery of an event already recorded is answered immediately and never applied a second time, so a duplicate never shows up as a second row.',
        'The list shows the 100 most recent events, while the counters above it cover every event ever received — so a counter can read far higher than the number of rows below it.',
        'The event payload is never shown on this page. The table itself belongs to the deployment rather than to any one workspace, so only the metadata and the message written by this application are displayed.',
    ],
    howItsBuilt: {
        summary:
            'The webhook endpoint is public — Stripe has no session and no workspace — so it verifies the signature locally, records the event keyed on its own id, and hands the actual work to a queued job. That split is deliberate: the handler shipped with the framework makes synchronous calls back to Stripe, which would hold a database transaction open for the whole round trip on a container with a small connection pool. Whether an event belongs here is decided by looking up the customer id it carries against the workspaces of this deployment; when nothing matches, the row is marked ignored rather than failed, and no job is queued at all. The distinction is what keeps the failure counter meaningful — an operations screen full of red that is not yours makes red mean nothing.',
        adr: {
            id: 'ADR-013',
            title: 'External calls leave the HTTP request and move to queues',
            url: adrUrl('ADR-013', 'apeluri-externe-in-cozi'),
        },
    },
};

export default webhookHealth;
