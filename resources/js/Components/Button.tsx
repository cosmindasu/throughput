import { Link } from '@inertiajs/react';
import type { ButtonHTMLAttributes, ComponentProps, MouseEvent } from 'react';

export type ButtonVariant = 'primary' | 'secondary' | 'danger';

const base =
    'inline-flex items-center justify-center gap-2 rounded-md px-3 py-1.5 text-sm font-medium transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus disabled:cursor-not-allowed disabled:opacity-60';

const variants: Record<ButtonVariant, string> = {
    // BR-PREF-03 — umplerea și eticheta acțiunii principale sunt identice în ambele
    // teme; hover-ul e MAI ÎNCHIS, deci contrastul doar crește la interacțiune.
    primary: 'bg-accent-fill text-accent-on hover:bg-accent-fill-hover',
    secondary: 'border border-control text-text-2 hover:bg-row-hover hover:text-text',
    danger: 'border border-danger text-danger hover:bg-danger-tint',
};

export function buttonClass(variant: ButtonVariant = 'secondary'): string {
    return `${base} ${variants[variant]}`;
}

interface ButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
    variant?: ButtonVariant;
    /**
     * „Cererea mea e deja în zbor." NU `disabled` — vezi docblock-ul de mai jos.
     * Butonul rămâne focusabil și în ordinea de Tab, dar nu mai declanșează nimic.
     */
    pending?: boolean;
    /** Eticheta cât timp `pending`; implicit `{children}…` („Save" → „Save…"). */
    pendingLabel?: string;
}

/**
 * Butonul aplicației. Nu există librărie de UI (§20.3: „fără librărie de UI generată"),
 * deci variantele stau aici, o singură dată, pe tokens — nu repetate pe fiecare ecran.
 *
 * Un buton pentru o acțiune la care utilizatorul nu are drept NU se randează dezactivat:
 * lipsește (FR-RBAC-01).
 *
 * `pending`, NU `disabled`, cât timp o cerere e în zbor — capcana măsurată din
 * `.ai/rules/frontend.md` („Focusul nu se pierde niciodată pe `<body>`"): `disabled` pus pe
 * elementul care ARE focusul (adică exact butonul pe care utilizatorul tocmai l-a apăsat)
 * îl blurează, iar browserul mută focusul pe `<body>` — utilizatorul de tastatură reia
 * pagina de la capăt, inclusiv în interiorul unui `<dialog>` modal. `aria-disabled` +
 * `onClick` neutralizat păstrează elementul focusabil, iar eticheta „…" dă feedback-ul
 * vizual pe care `disabled` l-ar fi dat. Tiparul exista deja copiat de mână în
 * `ConfirmDialog` și `ShipmentsSection`; trăiește aici, o singură dată, de la valul 3.
 *
 * `preventDefault()` e obligatoriu, nu decorativ: pe `type="submit"`, trimiterea implicită
 * a formularului (Enter într-un câmp) trece prin clic sintetic pe butonul de submit, deci
 * anularea clicului e și singura care oprește al doilea submit — `disabled` nativ nu mai e
 * acolo s-o facă.
 *
 * `disabled` nativ rămâne legitim pe un control care NU poate avea focus în momentul în
 * care se blochează (ex. un buton care depinde de un `<select>` pe care utilizatorul îl
 * operează chiar atunci) — de-aceea propul nu e scos, doar completat.
 */
export default function Button({
    variant = 'secondary',
    className = '',
    type = 'button',
    pending = false,
    pendingLabel,
    children,
    onClick,
    'aria-disabled': ariaDisabled,
    ...props
}: ButtonProps) {
    const handleClick = (event: MouseEvent<HTMLButtonElement>) => {
        if (pending) {
            event.preventDefault();
            return;
        }

        onClick?.(event);
    };

    return (
        // Spread-ul e PRIMUL, ca atributele calculate mai jos să nu poată fi suprascrise
        // tăcut de un `aria-disabled={undefined}` venit din apelant.
        <button
            {...props}
            type={type}
            onClick={handleClick}
            aria-disabled={pending || ariaDisabled || undefined}
            className={`${buttonClass(variant)} ${pending ? 'cursor-not-allowed opacity-60' : ''} ${className}`}
        >
            {pending ? (pendingLabel ?? <>{children}…</>) : children}
        </button>
    );
}

type ButtonLinkProps = ComponentProps<typeof Link> & { variant?: ButtonVariant };

export function ButtonLink({ variant = 'secondary', className = '', ...props }: ButtonLinkProps) {
    return <Link className={`${buttonClass(variant)} ${className}`} {...props} />;
}
