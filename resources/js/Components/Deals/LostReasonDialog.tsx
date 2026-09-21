import { useState } from 'react';
import type { TFunction } from 'i18next';
import { useTranslation } from 'react-i18next';
import ConfirmDialog from '@/Components/ConfirmDialog';
import { controlClass } from '@/Components/Form/Field';
import type { LostReason } from '@/types/generated';

const buildReasonOptions = (t: TFunction): Array<{ value: LostReason; label: string }> => [
    { value: 'price', label: t('lostReasons.price') },
    { value: 'competition', label: t('lostReasons.competition') },
    { value: 'timing', label: t('lostReasons.timing') },
    { value: 'other', label: t('lostReasons.other') },
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
    const { t } = useTranslation('deals');
    const [reason, setReason] = useState<LostReason>('price');
    const reasons = buildReasonOptions(t);

    return (
        <ConfirmDialog
            open={open}
            title={t('lostReasonDialog.title')}
            onClose={onCancel}
            onConfirm={() => onConfirm(reason)}
            confirmLabel={t('lostReasonDialog.confirmLabel')}
            confirmVariant="danger"
            processing={processing}
        >
            <label htmlFor="lost-reason" className="text-sm font-medium text-text">
                {t('lostReasonDialog.reasonLabel')}
            </label>
            <select
                id="lost-reason"
                className={`${controlClass} mt-1`}
                value={reason}
                onChange={(event) => setReason(event.target.value as LostReason)}
            >
                {reasons.map((option) => (
                    <option key={option.value} value={option.value}>
                        {option.label}
                    </option>
                ))}
            </select>
        </ConfirmDialog>
    );
}
