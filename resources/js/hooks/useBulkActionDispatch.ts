import { router } from '@inertiajs/react';
import { useState } from 'react';

interface UseBulkActionDispatchResult {
    processing: boolean;
    error: string | null;
    confirmOpen: boolean;
    closeConfirm: () => void;
    /**
     * Pornește operația: dacă `effectiveCount` trece pragul de confirmare (FR-BULK-01),
     * deschide dialogul în loc să trimită direct — la fel ca fluxul de reasignare original.
     */
    run: (extraPayload?: Record<string, unknown>) => void;
    /** Trimite direct, cu `confirmed: true` — apelat din dialogul de confirmare. */
    confirmAndDispatch: () => void;
}

/**
 * §13.1/§13.2 — logica de declanșare comună oricărei acțiuni bulk de SCRIERE, extrasă din
 * `BulkSelectionBar` ca să poată servi mai multe acțiuni în aceeași bară (reasignare,
 * anulare de draft-uri, preț în masă, activare/dezactivare), fiecare cu propriul URL,
 * payload și prag de confirmare comparat pe `effectiveCount` — N-ul EXACT pe care ACEA
 * acțiune l-ar atinge (poate diferi de `total`-ul resursei, ex: anularea de draft-uri).
 *
 * Payload-ul comun (`selectAllMatching`, `ids`, `confirmed`) e construit AICI, o singură
 * dată — fiecare acțiune adaugă doar câmpurile ei proprii (`extraPayload`).
 */
export function useBulkActionDispatch(
    url: string,
    matchingFilter: boolean,
    selectedIds: string[],
    effectiveCount: number,
    confirmationThreshold: number,
): UseBulkActionDispatchResult {
    const [processing, setProcessing] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [confirmOpen, setConfirmOpen] = useState(false);
    const [pendingPayload, setPendingPayload] = useState<Record<string, unknown>>({});

    const dispatch = (payload: Record<string, unknown>, confirmed: boolean) => {
        setProcessing(true);
        setError(null);

        router.post(
            url,
            {
                selectAllMatching: matchingFilter,
                ids: matchingFilter ? [] : selectedIds,
                ...payload,
                confirmed,
            },
            {
                // `onSuccess`/`onError`, NU `onFinish` (capcană de accesibilitate găsită de
                // mai multe ori în acest val) — `onFinish` închidea dialogul de confirmare
                // și la eroare (plafonul de rol, DEMO_MODE, pragul de confirmare fără
                // `confirmed`, un preț invalid), deci mesajul din `errors.selection`
                // apărea DUPĂ ce contextul lui (dialogul) dispăruse deja. Dialogul se
                // închide DOAR la succes; la eroare rămâne deschis, cu mesajul vizibil în el.
                onSuccess: () => {
                    setProcessing(false);
                    setConfirmOpen(false);
                },
                onError: (errors) => {
                    setProcessing(false);
                    setError((errors.selection as string) ?? 'This operation could not be started.');
                },
            },
        );
    };

    const run = (extraPayload: Record<string, unknown> = {}) => {
        if (effectiveCount > confirmationThreshold) {
            setPendingPayload(extraPayload);
            setConfirmOpen(true);
            return;
        }

        dispatch(extraPayload, false);
    };

    return {
        processing,
        error,
        confirmOpen,
        closeConfirm: () => setConfirmOpen(false),
        run,
        confirmAndDispatch: () => dispatch(pendingPayload, true),
    };
}
