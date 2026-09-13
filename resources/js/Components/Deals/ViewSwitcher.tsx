import { Link } from '@inertiajs/react';

interface ViewSwitcherProps {
    workspaceSlug: string;
    active: 'list' | 'board';
}

/**
 * Comutator List | Board (§9 task, punctul 5) — folosit de `Deals/Index` și
 * `Deals/Kanban`, ca cele două vederi să rămână la un click distanță.
 */
export default function ViewSwitcher({ workspaceSlug, active }: ViewSwitcherProps) {
    return (
        <div className="flex overflow-hidden rounded-md border border-control text-sm">
            <SwitcherLink href={`/${workspaceSlug}/deals`} active={active === 'list'}>
                List
            </SwitcherLink>
            <SwitcherLink href={`/${workspaceSlug}/deals/board`} active={active === 'board'}>
                Board
            </SwitcherLink>
        </div>
    );
}

function SwitcherLink({ href, active, children }: { href: string; active: boolean; children: string }) {
    return (
        <Link
            href={href}
            aria-current={active ? 'page' : undefined}
            className={`px-3 py-1.5 transition-colors ${active ? 'bg-accent-fill text-accent-on' : 'bg-surface text-text-2 hover:bg-row-hover'}`}
        >
            {children}
        </Link>
    );
}
