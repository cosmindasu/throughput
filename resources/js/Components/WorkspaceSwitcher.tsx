import { router } from '@inertiajs/react';
import { useEffect, useId, useRef, useState, type KeyboardEvent } from 'react';
import { useTranslation } from 'react-i18next';

interface WorkspaceOption {
    slug: string;
    name: string;
}

interface WorkspaceSwitcherProps {
    current: { slug: string; name: string; industry: string | null } | null;
    workspaces: WorkspaceOption[];
}

/**
 * Comutator de workspace (FR-TEN-01, specs.md §6.1). Randat MEREU în header,
 * chiar și cu un singur workspace în listă — criteriu de acceptanță explicit
 * (consecvență vizuală între roluri: demo.manager vede același control ca
 * demo.owner, doar cu o singură opțiune).
 *
 * „Mereu" înseamnă „pentru orice rol AUTENTIFICAT", nu „și fără date". Criteriul compară
 * demo.manager cu demo.owner — amândoi au cel puțin un workspace. Cazul cu ZERO n-a fost
 * niciodată în domeniul lui, și e real: `AppLayout` îmbracă și pagina publică
 * (`Welcome.tsx`), unde vizitatorul nu e autentificat și `workspaces` vine gol.
 *
 * Găsit la auditul din 2026-09-29, raportat ca „opțiunile se deschid în spate și nu se
 * văd". Nu era stivuire — `elementFromPoint` confirma lista DEASUPRA tuturor, cu
 * `z-20` intact și niciun context de stivuire pe lanțul de ascendenți. Lista avea
 * `height: 10px` și zero `role="option"`: cei 10px erau `py-1` plus bordura, adică lista
 * era GOALĂ, nu acoperită. Un control care nu poate selecta nimic și nu arată nimic nu e
 * un control — pentru un vizitator anonim „Select workspace" promite ceva ce nici nu
 * există încă.
 *
 * Pattern „listbox button" din WAI-ARIA Authoring Practices, scris manual:
 * proiectul nu are (și nu instalează) o librărie de UI headless. Escape
 * închide și readuce focusul pe buton; săgețile/Home/End mută selecția
 * activă; Enter/Space confirmă.
 *
 * Comutarea navighează la dashboard-ul noului workspace — echivalentul
 * „paginii curente în noul workspace, dacă există” din criteriul de
 * acceptanță ar cere ca serverul să rezolve dacă resursa curentă există și
 * acolo; contractul de rute primit pentru Faza 1 conține doar
 * `GET /{workspace}/dashboard`, deci fallback-ul e singurul comportament
 * implementabil aici (semnalat în raport).
 */
export default function WorkspaceSwitcher({ current, workspaces }: WorkspaceSwitcherProps) {
    const { t } = useTranslation('common');
    const [open, setOpen] = useState(false);
    const [activeIndex, setActiveIndex] = useState(0);
    const buttonRef = useRef<HTMLButtonElement>(null);
    const listRef = useRef<HTMLUListElement>(null);
    const listboxId = useId();

    const currentIndex = Math.max(
        0,
        workspaces.findIndex((workspace) => workspace.slug === current?.slug),
    );

    useEffect(() => {
        if (!open) {
            return;
        }

        const onDocumentPointerDown = (event: MouseEvent) => {
            const target = event.target as Node;
            if (!listRef.current?.contains(target) && !buttonRef.current?.contains(target)) {
                setOpen(false);
            }
        };

        document.addEventListener('mousedown', onDocumentPointerDown);
        return () => document.removeEventListener('mousedown', onDocumentPointerDown);
    }, [open]);

    useEffect(() => {
        if (open) {
            listRef.current?.focus();
        }
    }, [open]);

    const openList = () => {
        setActiveIndex(currentIndex);
        setOpen(true);
    };

    const closeList = (focusButton = true) => {
        setOpen(false);
        if (focusButton) {
            buttonRef.current?.focus();
        }
    };

    const selectWorkspace = (workspace: WorkspaceOption) => {
        closeList(false);
        if (workspace.slug === current?.slug) {
            return;
        }
        router.get(`/${workspace.slug}/dashboard`);
    };

    const onButtonKeyDown = (event: KeyboardEvent<HTMLButtonElement>) => {
        if (event.key === 'ArrowDown' || event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            openList();
        }
    };

    const onListKeyDown = (event: KeyboardEvent<HTMLUListElement>) => {
        switch (event.key) {
            case 'Escape':
                event.preventDefault();
                closeList();
                break;
            case 'ArrowDown':
                event.preventDefault();
                setActiveIndex((index) => Math.min(index + 1, workspaces.length - 1));
                break;
            case 'ArrowUp':
                event.preventDefault();
                setActiveIndex((index) => Math.max(index - 1, 0));
                break;
            case 'Home':
                event.preventDefault();
                setActiveIndex(0);
                break;
            case 'End':
                event.preventDefault();
                setActiveIndex(workspaces.length - 1);
                break;
            case 'Enter':
            case ' ': {
                event.preventDefault();
                const workspace = workspaces[activeIndex];
                if (workspace) {
                    selectWorkspace(workspace);
                }
                break;
            }
            case 'Tab':
                closeList(false);
                break;
        }
    };

    // Nimic de comutat ȘI niciun workspace curent de arătat — vezi docblock. Garda stă AICI,
    // după toate hook-urile, nu la începutul funcției: un `return` mai sus ar sări peste ele
    // și ar încălca regulile hook-urilor în momentul în care lista se umple după autentificare.
    if (workspaces.length === 0 && current === null) {
        return null;
    }

    return (
        <div className="relative">
            <button
                ref={buttonRef}
                type="button"
                aria-haspopup="listbox"
                aria-expanded={open}
                aria-controls={listboxId}
                onClick={() => (open ? closeList() : openList())}
                onKeyDown={onButtonKeyDown}
                className="flex items-center gap-2 rounded-md border border-control px-3 py-1.5 text-sm text-text transition-colors hover:bg-row-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus"
            >
                <span className="max-w-[16rem] truncate">{current?.name ?? t('common:workspaceSwitcher.placeholder')}</span>
                <span aria-hidden="true">▾</span>
            </button>

            {open && (
                <ul
                    id={listboxId}
                    ref={listRef}
                    role="listbox"
                    tabIndex={-1}
                    aria-label={t('common:workspaceSwitcher.label')}
                    aria-activedescendant={workspaces[activeIndex] ? `${listboxId}-option-${activeIndex}` : undefined}
                    onKeyDown={onListKeyDown}
                    className="absolute left-0 z-20 mt-1 max-h-64 w-64 overflow-auto rounded-md border border-border bg-overlay py-1 shadow-lg focus:outline-none"
                >
                    {workspaces.map((workspace, index) => (
                        <li
                            key={workspace.slug}
                            id={`${listboxId}-option-${index}`}
                            role="option"
                            aria-selected={workspace.slug === current?.slug}
                            onMouseEnter={() => setActiveIndex(index)}
                            onClick={() => selectWorkspace(workspace)}
                            className={`cursor-pointer px-3 py-1.5 text-sm ${index === activeIndex ? 'bg-row-hover' : ''} ${
                                workspace.slug === current?.slug ? 'font-medium text-accent-text' : 'text-text'
                            }`}
                        >
                            {workspace.name}
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
