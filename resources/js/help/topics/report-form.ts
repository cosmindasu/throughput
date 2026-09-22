import type { HelpTopicDefinition } from '@/help/types';

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
const reportForm: HelpTopicDefinition = {
    id: 'report-form',
};

export default reportForm;
