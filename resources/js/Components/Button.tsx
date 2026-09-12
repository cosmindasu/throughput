import { Link } from '@inertiajs/react';
import type { ButtonHTMLAttributes, ComponentProps } from 'react';

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
}

/**
 * Butonul aplicației. Nu există librărie de UI (§20.3: „fără librărie de UI generată"),
 * deci variantele stau aici, o singură dată, pe tokens — nu repetate pe fiecare ecran.
 *
 * Un buton pentru o acțiune la care utilizatorul nu are drept NU se randează dezactivat:
 * lipsește (FR-RBAC-01). `disabled` e doar pentru „se trimite deja".
 */
export default function Button({ variant = 'secondary', className = '', type = 'button', ...props }: ButtonProps) {
    return <button type={type} className={`${buttonClass(variant)} ${className}`} {...props} />;
}

type ButtonLinkProps = ComponentProps<typeof Link> & { variant?: ButtonVariant };

export function ButtonLink({ variant = 'secondary', className = '', ...props }: ButtonLinkProps) {
    return <Link className={`${buttonClass(variant)} ${className}`} {...props} />;
}
