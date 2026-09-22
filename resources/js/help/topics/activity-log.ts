import { adrUrl } from '@/help/adr';
import type { HelpTopic } from '@/help/types';

/**
 * `Activity/Index` — specs.md §17 (FR-AUD-01…04, US-AUD-01, BR-AUD-01), Faza 5, lotul E.
 *
 * Distinct de tab-ul „History" montat pe fiecare pagină de detaliu (Accounts, Contacts,
 * Deals, Products, Orders): acesta e ecranul TENANT-WIDE, cu filtre, nu o cronologie a unei
 * singure entități — de aici și restricția de rol de mai jos, care NU se aplică tab-urilor
 * de entitate (oricine poate vedea o variantă îi poate vedea și istoricul de preț).
 */
const activityLog: HelpTopic = {
    id: 'activity-log',
    title: 'Activity log',
    whatIsThis:
        "A tenant-wide record of who changed what and when — every create, update and delete on accounts, contacts, deals, products, variants, orders and invoices, plus bulk operations (one row per record touched) and membership changes: invitations sent, accepted or revoked, roles changed, members deactivated.",
    whatCanYouDo: [
        // Citatele urmează ETICHETELE afișate în dropdown, nu valorile brute ale enum-ului:
        // filtrul randa până acum `bulk_action`/`role_changed` direct din coloană, iar acum
        // arată eticheta tradusă. Capcana 1 a Valului 4 („subiectele citează literal
        // etichete de buton"), plătită aici pentru prima oară.
        'Filter by action type ("Created", "Updated", "Deleted", "Bulk Action", "Role Changed"...), by "Member", and by a "From"/"To" date range.',
        'Click the action name on a row to jump to the record it changed — variants are the exception, since they have no page of their own; their history is on the product.',
        'Open a bulk operation\'s "View in Activity Log" link (from its progress page) to see every row it touched, filtered to just that operation.',
        'See which fields a change touched in the "Changes" column — the old and new values themselves are shown side by side on the record\'s own "History" section, one click away.',
    ],
    rules: [
        'Owner and Manager see every action in the tenant. An Agent sees only their own actions here — everyone can still see the full history on a record\'s own "History" tab, regardless of who made the change.',
        'Viewer has no access to this screen at all (§7.4) — the record-level "History" tab stays visible to them, since it\'s part of viewing the record itself.',
        'Passwords, remember-me tokens and stored credentials never appear here, even partially — a changed field with one of those names is simply absent from the recorded change, not masked.',
        'Rows older than 36 months have their old/new values replaced with "[anonymized]" for contacts and users — the row itself, and which field changed, stays for statistics; the value doesn\'t.',
    ],
    howItsBuilt: {
        summary:
            'Writes happen two ways. Ordinary edits go through Eloquent model observers that fire an event, picked up by a queued listener — the request never waits on the log write. Bulk operations update many rows in one SQL statement, which Eloquent observers never see, so the bulk job reads each row before and after the change and writes one log row per row actually changed, in a single batch insert. Both paths share the same field-exclusion list (ADR-007) and the same "only the fields that changed" diff.',
        adr: {
            id: 'ADR-007',
            title: 'Activity log written in-house, not owen-it/laravel-auditing',
            url: adrUrl('ADR-007', 'audit-log-cod-propriu'),
        },
    },
};

export default activityLog;
