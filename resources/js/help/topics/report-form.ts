import type { HelpTopic } from '@/help/types';

/**
 * `Reports/Create` și `Reports/Edit` — un singur subiect pentru un formular comun
 * (`Components/Reports/ReportForm.tsx`), ca la `Accounts/Create` + `Accounts/Edit`.
 * specs.md §16.1 (câmpurile lui `report_definitions`), §16.2 pct. 1 (scadența pe ORĂ),
 * §16.3 (cele două rapoarte built-in), US-REP-01.
 *
 * Reconciliat cu codul la 2026-09-19 (lotul N, Faza 4): `ReportForm.tsx` („Report name",
 * „Format", fieldset-ul „Source" cu „Saved view export" + opțiunile built-in, fieldset-ul
 * „Schedule" cu „Frequency"/„Time (UTC)"/„Day of week"/„Day of month", „Recipients",
 * „Active", „Create report"/„Save changes"; sursa e READ-ONLY în `mode="edit"`),
 * `StoreReportRequest`/`UpdateReportRequest` (`recipients` obligatoriu cu minim 1 adresă,
 * fiecare validată individual ca `recipients.*`; vederea salvată trebuie să existe, să fie
 * vizibilă utilizatorului ȘI să fie pe o resursă cu export — `ExportableResources`:
 * Accounts, Contacts, Orders), `ReportSchedule` (ora se compară în UTC, doar pe componenta
 * de oră; ziua de lună peste ultima zi cade pe ultima zi), `ReportRecipients::normalize()`,
 * `InventoryValuationReport` (nota de cost/marjă din formular e un avertisment, nu o poartă).
 */
const reportForm: HelpTopic = {
    id: 'report-form',
    title: 'Report',
    whatIsThis:
        'The definition of a report: what it is built from, what kind of file it produces, when it runs on its own, and who receives it. The same form creates a new report and edits an existing one.',
    whatCanYouDo: [
        'Give it a "Report name" and choose a "Format" — CSV, XLSX or PDF.',
        'Choose the "Source": "Saved view export" together with one of your own or your team\'s saved views, or one of the built-ins — "Deal Velocity by Stage" or "Inventory Valuation".',
        'Set "Frequency" to "Manual only", "Daily", "Weekly" or "Monthly", then the "Time (UTC)" and, where it applies, the "Day of week" or "Day of month".',
        'List the "Recipients" — one email address per line, or comma-separated.',
        'Tick or untick "Active", then press "Create report" or "Save changes".',
    ],
    rules: [
        "The source is settled when you create the report. The edit form shows you what it is but won't let you change it: a report that changes what it reports on is a different report, so create a new one and delete the old one if you no longer want it.",
        'Scheduled times are UTC, not your local time and not the workspace\'s. There is no per-workspace time zone anywhere in the product, so "07:00" is unambiguous rather than quietly wrong — and the rest of the scheduled work, like the nightly demo reset, is already anchored the same way.',
        'The schedule only has hour resolution. The scheduler wakes up once an hour and compares the hour, so 07:00 and 07:45 behave identically — a report never runs at a specific minute.',
        'A monthly day past the end of a short month runs on that month\'s last day instead of being skipped. A report set to the 31st still goes out in February; the alternative would be a report that silently never runs in half the months of the year.',
        'At least one recipient is required, and every address is checked on its own — with three addresses and one typo, the error tells you which one, instead of rejecting the whole box.',
        'Only saved views on Accounts, Contacts or Orders can be a report source, because those are the lists the export mechanism can render to a file today. A saved view on anything else is refused with that exact reason rather than silently ignored.',
        '"Inventory Valuation" includes stock cost and the margin derived from it — figures Agents and Viewers never see anywhere else in the app. Every recipient gets the same file whatever their role, because there is one file per run and it is sent to all the addresses as it is. The form warns you when you pick that report; it does not stop you, because choosing the recipients is the control.',
        'Only Owner and Manager reach this form. An Agent who is a recipient can read a report and download its files, but never create, edit or delete one (specs.md §7.4).',
    ],
    howItsBuilt: {
        summary:
            'A saved-view report is not a copy of the rows: it stores a reference to the view, and each run re-applies that view\'s filters and sort through exactly the same list-export code path the "Export CSV" button uses, so a report and a manual export of the same view can never drift apart. That raises a question the interface can\'t answer — a filter like "owner is me" has no current user at 7am on a Monday — so a scheduled run resolves "me" as the person who created the report definition, the only role that can create one at all. Recipients are normalised on the way in and again on the way out, which is what keeps the Agent visibility rule honest. Consistency between the fields is enforced server-side too, not just by hiding inputs: a built-in report has its saved view cleared, and a "Manual only" report has its time and day cleared, so an inconsistent definition can\'t be posted around the form.',
    },
};

export default reportForm;
