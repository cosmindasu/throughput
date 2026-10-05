import { router } from '@inertiajs/react';
import { useEffect, useId, useRef, useState, type KeyboardEvent as ReactKeyboardEvent } from 'react';
import { useTranslation } from 'react-i18next';
import Icon from '@/Components/Icon';
import LostReasonDialog from '@/Components/Deals/LostReasonDialog';
import { buttonClass } from '@/Components/Button';
import type { DealStage, LostReason } from '@/types/generated';

interface MoveStageMenuProps {
    /**
     * `icon`: declanșator compact, pentru kanban — un buton cu etichetă completă pe fiecare
     * card ar ocupa mai mult decât afacerea însăși. Numele accesibil rămâne „Move to stage…",
     * textul doar devine `sr-only`.
     */
    variant?: 'text' | 'icon';
    workspaceSlug: string;
    dealId: string;
    currentStageId: string;
    stages: DealStage[];
    onError: (message: string) => void;
    onMoved?: (stage: DealStage) => void;
    /**
     * SC 2.4.4 / 4.1.2 — dat DOAR acolo unde meniul apare de mai multe ori pe aceeași
     * pagină (kanban: un declanșator per card, deci zeci de butoane „Move to stage…"
     * identice în lista de butoane a unui cititor de ecran). Pe `Deals/Show` există un
     * singur meniu, iar titlul e deja `h1`-ul paginii — acolo discriminatorul ar fi
     * redundanță, nu ajutor, deci propul rămâne opțional.
     */
    dealTitle?: string;
}

/**
 * FR-DEAL-01, OBLIGATORIU — alternativă non-drag pentru schimbarea etapei (WCAG 2.2,
 * SC 2.5.7): un buton „Move to stage…" cu un meniu operabil integral de la tastatură.
 * Enter/Space/↓ deschide, ↑/↓ navighează, Enter/Space alege, Esc închide și focusul
 * revine pe declanșator — construit ODATĂ cu drag & drop (`Deals/Kanban.tsx`), nu adăugat
 * după.
 *
 * O etapă `isLost` cere întâi motivul (`LostReasonDialog`, FR-DEAL-03) înainte de a trimite
 * cererea — atât de pe kanban, cât și din `Deals/Show`.
 */
export default function MoveStageMenu({ workspaceSlug, dealId, currentStageId, stages, onError, onMoved, dealTitle, variant = 'text' }: MoveStageMenuProps) {
    const { t } = useTranslation('deals');
    const [open, setOpen] = useState(false);
    const [activeIndex, setActiveIndex] = useState(0);
    const [pendingLostStage, setPendingLostStage] = useState<DealStage | null>(null);
    const [processing, setProcessing] = useState(false);
    const containerRef = useRef<HTMLDivElement>(null);
    const triggerRef = useRef<HTMLButtonElement>(null);
    const itemRefs = useRef<Array<HTMLButtonElement | null>>([]);
    const menuId = useId();

    const options = stages.filter((stage) => stage.id !== currentStageId);

    useEffect(() => {
        if (open) {
            itemRefs.current[activeIndex]?.focus();
        }
    }, [open, activeIndex]);

    useEffect(() => {
        if (!open) {
            return;
        }

        const handleOutsideClick = (event: MouseEvent) => {
            if (!containerRef.current?.contains(event.target as Node)) {
                setOpen(false);
            }
        };

        document.addEventListener('mousedown', handleOutsideClick);

        return () => document.removeEventListener('mousedown', handleOutsideClick);
    }, [open]);

    const close = (returnFocus = true) => {
        setOpen(false);
        if (returnFocus) {
            triggerRef.current?.focus();
        }
    };

    const submit = (stage: DealStage, lostReason?: LostReason) => {
        setProcessing(true);

        router.patch(
            `/${workspaceSlug}/deals/${dealId}/stage`,
            { to_stage_id: stage.id, ...(lostReason ? { lost_reason: lostReason } : {}) },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setPendingLostStage(null);
                    onMoved?.(stage);
                },
                onError: (errors) => {
                    setPendingLostStage(null);
                    onError(errors.to_stage_id ?? errors.lost_reason ?? t('moveStageMenu.error'));
                },
                onFinish: () => setProcessing(false),
            },
        );
    };

    const choose = (stage: DealStage) => {
        close();

        if (stage.isLost) {
            setPendingLostStage(stage);
            return;
        }

        submit(stage);
    };

    const onTriggerKeyDown = (event: ReactKeyboardEvent<HTMLButtonElement>) => {
        if (event.key === 'Enter' || event.key === ' ' || event.key === 'ArrowDown') {
            event.preventDefault();
            setActiveIndex(0);
            setOpen(true);
        }
    };

    const onMenuKeyDown = (event: ReactKeyboardEvent<HTMLUListElement>) => {
        switch (event.key) {
            case 'ArrowDown':
                event.preventDefault();
                setActiveIndex((index) => (index + 1) % options.length);
                break;
            case 'ArrowUp':
                event.preventDefault();
                setActiveIndex((index) => (index - 1 + options.length) % options.length);
                break;
            case 'Home':
                event.preventDefault();
                setActiveIndex(0);
                break;
            case 'End':
                event.preventDefault();
                setActiveIndex(options.length - 1);
                break;
            case 'Escape':
                event.preventDefault();
                close();
                break;
            case 'Enter':
            case ' ':
                event.preventDefault();
                if (options[activeIndex]) {
                    choose(options[activeIndex]);
                }
                break;
            case 'Tab':
                close(false);
                break;
        }
    };

    return (
        <div ref={containerRef} className="relative inline-block text-left">
            <button
                ref={triggerRef}
                type="button"
                title={variant === 'icon' ? t('moveStageMenu.trigger') : undefined}
                aria-haspopup="menu"
                aria-expanded={open}
                aria-controls={open ? menuId : undefined}
                onClick={() => {
                    setActiveIndex(0);
                    setOpen((value) => !value);
                }}
                onKeyDown={onTriggerKeyDown}
                className={
                    variant === 'icon'
                        ? 'inline-flex size-7 items-center justify-center rounded-md text-text-2 transition-colors hover:bg-row-hover hover:text-text focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus'
                        : `${buttonClass('secondary')} text-xs`
                }
            >
                {/* Discriminatorul e `sr-only` DUPĂ textul vizibil, nu un `aria-label` care
                    l-ar înlocui: numele accesibil tot ÎNCEPE cu „Move to stage…" (SC 2.5.3
                    Label in Name) și rămâne găsibil după textul vizibil. */}
                {/*
                    Textul devine `sr-only`, NU dispare: numele accesibil al declanșatorului
                    rămâne „Move to stage…", pe care `deals-pipeline.spec.ts` îl caută cu
                    `getByRole('button', { name: 'Move to stage…' })` — și care e, mai
                    important, singurul lucru care spune ce face butonul.
                */}
                {/* `title` pe varianta compactă: indiciul paginii („use «Move to stage…»")
                    numește un control care, pe kanban, se vede ca „⋯". Numele accesibil îl
                    avea deja; `title` îl face descoperibil și cu mouse-ul. */}
                {variant === 'icon' && <Icon name="more" size={16} />}
                <span className={variant === 'icon' ? 'sr-only' : undefined}>{t('moveStageMenu.trigger')}</span>
                {dealTitle && <span className="sr-only"> {t('moveStageMenu.triggerFor', { title: dealTitle })}</span>}
            </button>

            {open && (
                <ul
                    id={menuId}
                    role="menu"
                    aria-label={t('moveStageMenu.ariaLabel')}
                    onKeyDown={onMenuKeyDown}
                    className={`absolute z-10 mt-1 w-48 rounded-md border border-border bg-overlay py-1 shadow-lg ${variant === 'icon' ? 'right-0' : ''}`}
                >
                    {options.map((stage, index) => (
                        <li key={stage.id} role="none">
                            <button
                                type="button"
                                role="menuitem"
                                tabIndex={-1}
                                ref={(element) => {
                                    itemRefs.current[index] = element;
                                }}
                                onClick={() => choose(stage)}
                                className="block w-full px-3 py-1.5 text-left text-sm text-text hover:bg-row-hover focus-visible:bg-row-hover focus-visible:outline-none"
                            >
                                {stage.name}
                            </button>
                        </li>
                    ))}
                </ul>
            )}

            <LostReasonDialog
                open={pendingLostStage !== null}
                processing={processing}
                onCancel={() => setPendingLostStage(null)}
                onConfirm={(reason) => pendingLostStage && submit(pendingLostStage, reason)}
            />
        </div>
    );
}
