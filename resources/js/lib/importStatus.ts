import type { TFunction } from 'i18next';
import type { BadgeTone } from '@/Components/StatusBadge';
import type { ImportStatus } from '@/types/generated';

/**
 * Tentă comună pentru chip-ul de status (`StatusBadge`) — folosită ATÂT în `Imports/Index`
 * (coloana Status), CÂT ȘI în `Imports/Show` (chip-ul de lângă titlul de pas). Găsit la
 * audit: `Show.tsx` afișa enum-ul brut (`completed_with_errors`) direct pe chip, în loc de
 * eticheta umană pe care `Index.tsx` o are alături — un singur loc, ca cele două ecrane să
 * nu diveargă din nou.
 *
 * Distinct de titlul de pas („Step 3 of 4 — Validating…", `Imports/Show.tsx`): acela descrie
 * POZIȚIA în flux, nepotrivit pentru un chip scurt.
 *
 * Culorile NU trec prin `t()` — rămân `Record` static (Val 3, „Lot I18N").
 */
export const IMPORT_STATUS_TONES: Record<ImportStatus, BadgeTone> = {
    uploaded: 'neutral',
    mapped: 'neutral',
    validating: 'accent',
    validated: 'info',
    importing: 'accent',
    completed: 'success',
    completed_with_errors: 'warning',
    failed: 'danger',
};

/**
 * Etichetele chip-ului de status — fabrică parametrizată pe `t`, nu `Record` static
 * (`.ai/rules/frontend.md` §6, „Dacă un tablou de constante la nivel de modul conține
 * etichete... transformă-l într-o fabrică parametrizată și memoizeaz-o"), pe tiparul
 * `buildStepTitles(t)` din `Pages/Imports/Show.tsx`. Fișierul e `.ts`, nu `.tsx`, dar nu
 * randează JSX — doar construiește obiectul, deci nu are nevoie de `useTranslation` propriu;
 * apelantul (`Imports/Index.tsx`, `Imports/Show.tsx`) memoizează rezultatul cu `useMemo`.
 *
 * Chei în namespace-ul `imports`, `statusLabels.*` — nivel de top, nu sub `index`/`show`,
 * fiindcă e literal PARTAJAT între cele două ecrane, exact ca `IMPORT_STATUS_TONES` de mai
 * sus.
 */
export function buildImportStatusLabels(t: TFunction): Record<ImportStatus, string> {
    return {
        uploaded: t('imports:statusLabels.uploaded'),
        mapped: t('imports:statusLabels.mapped'),
        validating: t('imports:statusLabels.validating'),
        validated: t('imports:statusLabels.validated'),
        importing: t('imports:statusLabels.importing'),
        completed: t('imports:statusLabels.completed'),
        completed_with_errors: t('imports:statusLabels.completed_with_errors'),
        failed: t('imports:statusLabels.failed'),
    };
}
