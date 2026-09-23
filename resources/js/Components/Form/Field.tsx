import { useId, type ReactNode } from 'react';

/**
 * Clasa comună a controalelor de formular. Bordura e `--control`, nu `--border`: e un
 * element non-text purtător de sens, deci ține ≥ 3:1 (SC 1.4.11, măsurat în urls.md).
 */
export const controlClass =
    'block w-full rounded-md border border-control bg-surface px-3 py-1.5 text-sm text-text placeholder:text-text-3 focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-focus aria-[invalid=true]:border-danger';

export interface FieldControlProps {
    id: string;
    'aria-describedby'?: string;
    'aria-invalid'?: true;
}

interface FieldProps {
    label: string;
    error?: string;
    hint?: string;
    required?: boolean;
    children: (control: FieldControlProps) => ReactNode;
}

/**
 * Etichetă + control + indiciu + eroare, legate prin `id`/`aria-describedby`, ca un
 * cititor de ecran să anunțe eroarea odată cu câmpul, nu doar s-o afișeze vizual.
 *
 * Render prop, nu `cloneElement`: controlul rămâne explicit în pagină (input, select,
 * textarea), iar legăturile ARIA nu depind de ce element se întâmplă să fie copilul.
 */
export default function Field({ label, error, hint, required = false, children }: FieldProps) {
    const id = useId();
    const hintId = `${id}-hint`;
    const errorId = `${id}-error`;
    const describedBy = [hint ? hintId : null, error ? errorId : null].filter(Boolean).join(' ') || undefined;

    return (
        <div className="flex flex-col gap-1">
            <label htmlFor={id} className="text-sm font-medium text-text">
                {label}
                {required && (
                    <span aria-hidden="true" className="text-danger">
                        {' '}
                        *
                    </span>
                )}
            </label>
            {children({ id, 'aria-describedby': describedBy, 'aria-invalid': error ? true : undefined })}
            {hint && (
                <p id={hintId} className="text-xs text-text-3">
                    {hint}
                </p>
            )}
            {/* Audit de accesibilitate P1 (A11Y-01), SC 3.3.1/4.1.3 — `role="alert"` anunță
                eroarea la cititorul de ecran în momentul în care apare (nu doar la focus,
                cum face `aria-describedby` singur). Fără dublare absurdă: un paragraf
                prezent DEJA la montare (ex. eroare server-side pe prima randare) nu se
                anunță — browserele anunță doar mutațiile ulterioare ale unei regiuni
                `role="alert"`, nu conținutul ei inițial. Focusul pe primul câmp invalid
                după un submit eșuat e mecanismul global din `lib/focusOnValidationError.ts`
                (`router.on('error', …)`, înregistrat o singură dată în `app.tsx`) — niciun
                cod aici, ca să nu repete tiparul în cele 31 de fișiere care importă `Field`. */}
            {error && (
                <p id={errorId} role="alert" className="text-xs text-danger">
                    {error}
                </p>
            )}
        </div>
    );
}
