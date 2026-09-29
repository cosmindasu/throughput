import type { BadgeTone } from '@/Components/StatusBadge';

/**
 * Ce fel de lucru s-a întâmplat, în vocabularul de tente al design-system-ului.
 *
 * Gruparea e o judecată SEMANTICĂ, nu una de culoare — culorile stau în tokens, iar aici
 * nu apare niciuna. Criteriul e „ce mă face să mă opresc când citesc un jurnal":
 *
 * - **distructiv** (`deleted`) — singurul lucru care nu se poate desface dintr-o listă;
 * - **de securitate** (`login_failed`, `role_changed`) — nu e o greșeală, dar e exact
 *   genul de rând pe care îl cauți când ceva nu e în regulă;
 * - **de creare** (`created`, `imported`) — a apărut ceva ce nu exista;
 * - **de modificare** (`updated`, `bulk_action`) — a existat și înainte, s-a schimbat;
 * - **de rutină** (`login`, `exported`) — citiri și prezențe, zgomotul de fond al unui
 *   jurnal sănătos.
 *
 * `action` vine BRUT din `ActivityLog::ACTIONS` (vezi `ActivityEntryResource` și
 * `HistoryEntryResource`, care îl expun amândouă). Nu se compune niciun text din el:
 * fraza vizibilă e `description`/`actionLabel`, tradusă server-side.
 *
 * **Culoarea nu e singurul indiciu**, niciodată (SC 1.4.1): punctul colorat stă mereu
 * lângă eticheta scrisă a acțiunii, deci nu duce informație pe care textul n-o are deja.
 * De-aia e și `aria-hidden` la fiecare loc unde se randează.
 */
const TONE_BY_ACTION: Record<string, BadgeTone> = {
    deleted: 'danger',

    login_failed: 'warning',
    role_changed: 'warning',

    created: 'success',
    imported: 'success',

    updated: 'info',
    bulk_action: 'info',

    login: 'neutral',
    exported: 'neutral',
};

/**
 * Enum-ul e închis în migrație, dar `action` sosește ca `string` — o valoare necunoscută
 * (o migrație viitoare care adaugă un tip de acțiune, un rând vechi) primește `neutral`,
 * nu o culoare inventată și nici o excepție. Un jurnal care cade pentru că nu știe ce
 * culoare să dea unui rând ar fi mai rău decât un punct gri.
 */
export function activityTone(action: string): BadgeTone {
    return TONE_BY_ACTION[action] ?? 'neutral';
}
