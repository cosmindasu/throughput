import { useState } from 'react';
import ConfirmDialog from '@/Components/ConfirmDialog';
import { controlClass } from '@/Components/Form/Field';
import type { LostReason } from '@/types/generated';

const REASONS: Array<{ value: LostReason; label: string }> = [
    { value: 'price', label: 'Price' },
    { value: 'competition', label: 'Competition' },
    { value: 'timing', label: 'Timing' },
    { value: 'other', label: 'Other' },
];

interface LostReasonDialogProps {
    open: boolean;
    processing?: boolean;
    onCancel: () => void;
    onConfirm: (reason: LostReason) => void;
}

/**
 * FR-DEAL-03 — un motiv obligatoriu, dintr-o listă închisă, înainte de a marca un deal
 * ca „Lost" (drag SAU meniul „Move to stage…", §9.3). Pe `<dialog>` nativ, ca
 * `ConfirmDialog`: focus trap și `Esc` gratuite de la browser.
 */
export default function LostReasonDialog({ open, processing = false, onCancel, onConfirm }: LostReasonDialogProps) {
    const [reason, setReason] = useState<LostReason>('price');

    return (
        <ConfirmDialog
            open={open}
            title="Mark deal as Lost"
            onClose={onCancel}
            onConfirm={() => onConfirm(reason)}
            confirmLabel="Mark as Lost"
            confirmVariant="danger"
            processing={processing}
        >
            <label htmlFor="lost-reason" className="text-sm font-medium text-text">
                Reason
            </label>
            <select
                id="lost-reason"
                className={`${controlClass} mt-1`}
                value={reason}
                onChange={(event) => setReason(event.target.value as LostReason)}
            >
                {REASONS.map((option) => (
                    <option key={option.value} value={option.value}>
                        {option.label}
                    </option>
                ))}
            </select>
        </ConfirmDialog>
    );
}
