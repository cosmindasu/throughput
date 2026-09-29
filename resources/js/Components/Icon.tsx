import type { SVGProps } from 'react';

/**
 * Setul de iconuri al aplicației — SVG inline, local, FĂRĂ dependență nouă.
 *
 * Motivul e același cu al fonturilor (`app.css`): proiectul se auto-găzduiește deliberat,
 * iar un pachet de iconuri ar aduce un arbore întreg pentru ~15 forme. Conturul e desenat
 * la 24×24 cu `stroke="currentColor"`, deci iconul moștenește culoarea textului din jur —
 * regula „niciun cod de culoare literal" din `.ai/rules/frontend.md` rămâne respectată fără
 * ca fișierul ăsta să cunoască vreun token.
 *
 * Geometria e cea din Lucide (licență ISC), redesenată aici ca date, nu importată.
 *
 * **Accesibilitate:** iconurile de aici sunt DECORATIVE. Fiecare apare lângă textul pe care
 * l-ar descrie (eticheta din navigație, eticheta plăcii KPI), deci un nume accesibil ar fi
 * al doilea nume pentru același lucru — exact redundanța pe care regula badge-ului din
 * `AppLayout` o evită deja. De-asta `aria-hidden` e pus AICI, o dată, nu lăsat pe seama
 * fiecărui apelant: un icon care ajunge în arborele de accesibilitate din uitare e mai
 * greu de observat decât unul lipsă.
 */
export type IconName =
    | 'accounts'
    | 'contacts'
    | 'deals'
    | 'products'
    | 'orders'
    | 'invoices'
    | 'reports'
    | 'imports'
    | 'unassigned'
    | 'activity'
    | 'settings'
    | 'money'
    | 'alert'
    | 'clock'
    | 'inbox';

/**
 * Fiecare intrare e lista de `d`-uri ale conturului. Cercurile sunt scrise tot ca `path`
 * (două arce), ca randarea să fie o singură buclă peste `<path>` — fără ramuri pe tip de
 * formă în componentă.
 */
const PATHS: Record<IconName, string[]> = {
    accounts: [
        'M6 22V4a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v18Z',
        'M6 12H4a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h2',
        'M18 9h2a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2h-2',
        'M10 6h4M10 10h4M10 14h4M10 18h4',
    ],
    contacts: [
        'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2',
        'M13 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0Z',
        'M22 21v-2a4 4 0 0 0-3-3.87',
        'M16 3.13a4 4 0 0 1 0 7.75',
    ],
    deals: ['M16 7h6v6', 'm22 7-8.5 8.5-5-5L2 17'],
    products: [
        'm7.5 4.27 9 5.15',
        'M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z',
        'm3.3 7 8.7 5 8.7-5',
        'M12 22V12',
    ],
    orders: [
        'M9 21a1 1 0 1 1-2 0 1 1 0 0 1 2 0Z',
        'M20 21a1 1 0 1 1-2 0 1 1 0 0 1 2 0Z',
        'M1 1h4l2.68 12.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6',
    ],
    invoices: [
        'M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z',
        'M14 2v4a2 2 0 0 0 2 2h4',
        'M16 13H8M16 17H8M10 9H8',
    ],
    reports: ['M3 3v16a2 2 0 0 0 2 2h16', 'M18 17V9M13 17V5M8 17v-3'],
    imports: ['M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4', 'm17 8-5-5-5 5', 'M12 3v12'],
    unassigned: [
        'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2',
        'M13 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0Z',
        'm17 8 5 5M22 8l-5 5',
    ],
    activity: ['M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8', 'M3 3v5h5', 'M12 7v5l3.5 2'],
    settings: [
        'M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2Z',
        'M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z',
    ],
    money: ['M12 2v20', 'M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6'],
    alert: [
        'm21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z',
        'M12 9v4',
        'M12 17h.01',
    ],
    clock: ['M22 12a10 10 0 1 1-20 0 10 10 0 0 1 20 0Z', 'M12 6v6l4 2'],
    inbox: [
        'M22 12h-6l-2 3h-4l-2-3H2',
        'M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11Z',
    ],
};

interface IconProps extends Omit<SVGProps<SVGSVGElement>, 'name' | 'children'> {
    name: IconName;
    /** Latura, în px. Implicit 16 — dimensiunea din navigație și din etichetele de placă. */
    size?: number;
}

export default function Icon({ name, size = 16, className = '', ...rest }: IconProps) {
    return (
        <svg
            aria-hidden="true"
            focusable="false"
            xmlns="http://www.w3.org/2000/svg"
            width={size}
            height={size}
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth={2}
            strokeLinecap="round"
            strokeLinejoin="round"
            // `shrink-0`: într-un rând flex cu text lung (eticheta de navigație în franceză)
            // un SVG fără el se turtește în loc să lase textul să se rupă.
            className={`shrink-0 ${className}`}
            {...rest}
        >
            {PATHS[name].map((d) => (
                <path key={d} d={d} />
            ))}
        </svg>
    );
}
