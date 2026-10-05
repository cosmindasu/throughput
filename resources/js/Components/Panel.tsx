import type { ReactNode } from 'react';

interface PanelProps {
    title: string;
    action?: ReactNode;
    className?: string;
    children: ReactNode;
}

/**
 * Cardul de dashboard — în loc de `section.rounded-lg.border…` copiat de mână pe fiecare pagină.
 * `aria-label` rămâne titlul (suita a11y așteaptă regiunea „Recent activity" după nume).
 *
 * **`min-w-0` nu e cosmetic.** Un element de grid sau de flex are implicit `min-width: auto`,
 * adică refuză să coboare sub lățimea conținutului său. Un grafic care se măsoară singur
 * (`useContainerWidth`) intră atunci într-o buclă: randează la lățimea de rezervă, panoul
 * crește ca să-l cuprindă, iar măsurătoarea următoare confirmă lățimea umflată — chiar cea
 * pe care tocmai a provocat-o. Văzut pe dashboard la 416px: graficul rămânea la 640px și
 * împingea pagina la 274px de derulare orizontală. `min-w-0` rupe bucla la sursă: panoul se
 * poate strânge, deci măsurătoarea descrie pista, nu invers.
 */
export default function Panel({ title, action, className = '', children }: PanelProps) {
    return (
        <section aria-label={title} className={`min-w-0 rounded-lg border border-border bg-surface p-4 ${className}`}>
            <div className="flex items-center justify-between gap-3">
                <h2 className="text-sm font-medium text-text-2">{title}</h2>
                {action}
            </div>
            <div className="mt-3">{children}</div>
        </section>
    );
}
