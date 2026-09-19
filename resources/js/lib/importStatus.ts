import type { BadgeTone } from '@/Components/StatusBadge';
import type { ImportStatus } from '@/types/generated';

/**
 * Etichetă + tentă comune pentru chip-ul de status (`StatusBadge`) — folosite ATÂT în
 * `Imports/Index` (coloana Status), CÂT ȘI în `Imports/Show` (chip-ul de lângă titlul de
 * pas). Găsit la audit: `Show.tsx` afișa enum-ul brut (`completed_with_errors`) direct pe
 * chip, în loc de eticheta umană pe care `Index.tsx` o are alături — un singur loc, ca cele
 * două ecrane să nu diveargă din nou.
 *
 * Distinct de titlul de pas („Step 3 of 4 — Validating…", `Imports/Show.tsx`): acela descrie
 * POZIȚIA în flux, nepotrivit pentru un chip scurt.
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

export const IMPORT_STATUS_LABELS: Record<ImportStatus, string> = {
    uploaded: 'Uploaded',
    mapped: 'Mapped',
    validating: 'Validating…',
    validated: 'Ready to import',
    importing: 'Importing…',
    completed: 'Completed',
    completed_with_errors: 'Completed with errors',
    failed: 'Failed',
};
