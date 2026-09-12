import type { ReactNode } from 'react';

interface PageHeaderProps {
    title: string;
    description?: ReactNode;
    actions?: ReactNode;
}

/**
 * Titlul unui ecran + acțiunile lui. Singurul `h1` al paginii (ierarhia de titluri e
 * verificată de axe-core, §20.3).
 */
export default function PageHeader({ title, description, actions }: PageHeaderProps) {
    return (
        <div className="flex flex-wrap items-start justify-between gap-4">
            <div className="min-w-0">
                <h1 className="text-xl font-semibold text-text">{title}</h1>
                {description && <p className="mt-1 text-sm text-text-2">{description}</p>}
            </div>
            {actions && <div className="flex flex-wrap items-center gap-2">{actions}</div>}
        </div>
    );
}
