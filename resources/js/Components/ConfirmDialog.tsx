import { useEffect, useId, useRef, type ReactNode } from 'react';
import Button, { type ButtonVariant } from '@/Components/Button';

interface ConfirmDialogProps {
    open: boolean;
    title: string;
    children: ReactNode;
    /** Fără `onConfirm`, dialogul doar informează (ex: de ce o ștergere e refuzată). */
    onConfirm?: () => void;
    confirmLabel?: string;
    confirmVariant?: ButtonVariant;
    processing?: boolean;
    onClose: () => void;
}

/**
 * Dialog de confirmare pe `<dialog>` nativ, cu `showModal()`: browserul dă gratuit
 * capcana de focus, închiderea cu Esc și fundalul inert — exact partea pe care o
 * implementare de mână o greșește primul. Focusul revine pe declanșator la închidere.
 */
export default function ConfirmDialog({
    open,
    title,
    children,
    onConfirm,
    confirmLabel = 'Confirm',
    confirmVariant = 'primary',
    processing = false,
    onClose,
}: ConfirmDialogProps) {
    const ref = useRef<HTMLDialogElement>(null);
    const titleId = useId();

    useEffect(() => {
        const dialog = ref.current;

        if (!dialog) {
            return;
        }

        if (open && !dialog.open) {
            dialog.showModal();
        } else if (!open && dialog.open) {
            dialog.close();
        }
    }, [open]);

    return (
        <dialog
            ref={ref}
            aria-labelledby={titleId}
            onClose={onClose}
            className="m-auto rounded-lg border border-border bg-overlay p-0 text-text backdrop:bg-scrim"
        >
            <div className="w-[min(28rem,90vw)] p-5">
                <h2 id={titleId} className="text-base font-semibold text-text">
                    {title}
                </h2>
                <div className="mt-2 text-sm text-text-2">{children}</div>
                <div className="mt-5 flex justify-end gap-2">
                    <Button onClick={onClose}>{onConfirm ? 'Cancel' : 'Close'}</Button>
                    {onConfirm && (
                        <Button variant={confirmVariant} onClick={onConfirm} disabled={processing}>
                            {confirmLabel}
                        </Button>
                    )}
                </div>
            </div>
        </dialog>
    );
}
