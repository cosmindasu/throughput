import { useEffect, useId, useRef, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import Button, { type ButtonVariant } from '@/Components/Button';

interface ConfirmDialogProps {
    open: boolean;
    title: string;
    children: ReactNode;
    /** Fără `onConfirm`, dialogul doar informează (ex: de ce o ștergere e refuzată). */
    onConfirm?: () => void;
    /** Implicit `t('common:actions.confirm')` — vezi corpul funcției, un default literal
     * n-ar putea apela `t()`. */
    confirmLabel?: string;
    confirmVariant?: ButtonVariant;
    processing?: boolean;
    onClose: () => void;
}

/**
 * Dialog de confirmare pe `<dialog>` nativ, cu `showModal()`: browserul dă gratuit
 * capcana de focus, închiderea cu Esc și fundalul inert — exact partea pe care o
 * implementare de mână o greșește primul. Focusul revine pe declanșator la închidere.
 *
 * Butonul de confirmare NU folosește `disabled` nativ cât `processing` e adevărat
 * (capcană de accesibilitate găsită de mai multe ori în acest val, pe ecrane diferite):
 * `disabled` pe elementul care ARE focusul (exact butonul pe care utilizatorul tocmai
 * l-a apăsat) îl blurează — browserul mută focusul pe `<body>`, chiar și în interiorul
 * unui `<dialog>` modal, ceea ce rupe tab-order-ul din modal. `aria-disabled` + un
 * `onClick` no-op păstrează elementul focusabil (doar non-interactiv), iar eticheta
 * schimbată în „{confirmLabel}…" dă feedback-ul vizual pe care `disabled` l-ar fi dat.
 */
export default function ConfirmDialog({
    open,
    title,
    children,
    onConfirm,
    confirmLabel,
    confirmVariant = 'primary',
    processing = false,
    onClose,
}: ConfirmDialogProps) {
    const { t } = useTranslation('common');
    const ref = useRef<HTMLDialogElement>(null);
    const titleId = useId();
    const resolvedConfirmLabel = confirmLabel ?? t('common:actions.confirm');

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
                    <Button onClick={onClose}>{onConfirm ? t('common:actions.cancel') : t('common:actions.close')}</Button>
                    {onConfirm && (
                        <Button
                            variant={confirmVariant}
                            onClick={processing ? undefined : onConfirm}
                            aria-disabled={processing || undefined}
                            className={processing ? 'cursor-not-allowed opacity-60' : ''}
                        >
                            {processing ? `${resolvedConfirmLabel}…` : resolvedConfirmLabel}
                        </Button>
                    )}
                </div>
            </div>
        </dialog>
    );
}
