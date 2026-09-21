import { useEffect, useId, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import type { TFunction } from 'i18next';
import Button from '@/Components/Button';
import Field, { controlClass } from '@/Components/Form/Field';
import type { MembershipOpenRecords, MembershipRow } from '@/types/generated';

interface ActiveMemberOption {
    id: string;
    name: string;
}

interface DeactivateMemberDialogProps {
    open: boolean;
    member: MembershipRow | null;
    activeMembers: ActiveMemberOption[];
    processing: boolean;
    /** Audit de accesibilitate (P1) — `page.props.errors` din cererea eșuată curentă. */
    errors: Record<string, string>;
    onClose: () => void;
    onReassignAndDeactivate: (newOwnerUserId: string) => void;
    onDeactivateAnyway: () => void;
}

/**
 * Motorul CLDR al i18next, nu `=== 1 ? … : …` scris de mână — cele două tipare de aici
 * erau documentate explicit în ADR-022 printre cele trei găsite la deschiderea lotului.
 */
function summarize(openRecords: MembershipOpenRecords, t: TFunction<'settings'>): string {
    const deals = t('settings:deactivateMemberDialog.summary.deals', { count: openRecords.deals });
    const orders = t('settings:deactivateMemberDialog.summary.orders', { count: openRecords.orders });

    return t('settings:deactivateMemberDialog.summary.joiner', { deals, orders });
}

/**
 * US-TEN-03, Gherkin — dialogul de confirmare cu textul și numerele exacte. Pe `<dialog>`
 * nativ, ca `ConfirmDialog`, dar cu formă proprie: DOUĂ acțiuni de confirmare distincte
 * („Reassign and deactivate" / „Deactivate anyway"), nu una singură — `ConfirmDialog` nu
 * are cum să exprime asta fără să-i schimbe contractul pentru toți ceilalți apelanți.
 *
 * BR-TEN-01 — pentru ultimul Owner activ, dialogul arată DOAR mesajul de blocare, fără
 * niciun buton de acțiune: „nu există buton de forțare" e literal, nu doar server-side.
 *
 * Apelantul îl randează cu `key={target?.id}`: la schimbarea membrului țintă, componenta
 * se remontează, deci `newOwnerUserId` local pornește mereu gol — fără un `useEffect` care
 * ar seta starea sincron la fiecare deschidere (regula `react-hooks/set-state-in-effect`).
 * La o EROARE (target neschimbat), cheia rămâne aceeași — dialogul NU se remontează, deci
 * selecția utilizatorului supraviețuiește refuzului.
 */
export default function DeactivateMemberDialog({
    open,
    member,
    activeMembers,
    processing,
    errors,
    onClose,
    onReassignAndDeactivate,
    onDeactivateAnyway,
}: DeactivateMemberDialogProps) {
    const { t } = useTranslation('settings');
    const ref = useRef<HTMLDialogElement>(null);
    const titleId = useId();
    const descriptionId = useId();
    const [newOwnerUserId, setNewOwnerUserId] = useState('');
    // Audit de accesibilitate (P1, pct. 3) — CARE buton a fost apăsat, ca doar acela să
    // arate „…ing" cât `processing` e adevărat; celălalt rămâne cu eticheta lui obișnuită.
    const [pendingAction, setPendingAction] = useState<'reassign' | 'anyway' | null>(null);

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

    // Audit de accesibilitate (P1, pct. 9) — Esc declanșează `cancel` ÎNAINTEA lui
    // `close()`; cât o cerere e în curs, `preventDefault()` ține dialogul deschis, la fel
    // ca butonul „Cancel" (care deja nu face nimic cât `processing`).
    useEffect(() => {
        const dialog = ref.current;

        if (!dialog) {
            return;
        }

        const handleCancel = (event: Event) => {
            if (processing) {
                event.preventDefault();
            }
        };

        dialog.addEventListener('cancel', handleCancel);

        return () => dialog.removeEventListener('cancel', handleCancel);
    }, [processing]);

    const requestClose = () => {
        if (processing) {
            return;
        }

        onClose();
    };

    if (!member) {
        return (
            <dialog ref={ref} onClose={onClose} className="m-auto rounded-lg border border-border bg-overlay p-0 text-text backdrop:bg-scrim" />
        );
    }

    const reassignOptions = activeMembers.filter((option) => option.id !== member.user.id);
    const hasOpenRecords = member.openRecords.total > 0;
    // Audit de accesibilitate (P1, pct. 1) — o eroare FĂRĂ câmp (ultimul Owner apărut
    // concurent, rol insuficient, plafonul DEMO_MODE pe reasignare) — vezi
    // `MembersController::deactivate()` (`errors.deactivate`) și
    // `DispatchBulkOperationAction` (`errors.selection`).
    const generalError = errors.deactivate ?? errors.selection;

    return (
        <dialog
            ref={ref}
            aria-labelledby={titleId}
            aria-describedby={descriptionId}
            onClose={onClose}
            className="m-auto rounded-lg border border-border bg-overlay p-0 text-text backdrop:bg-scrim"
        >
            <div className="w-[min(30rem,90vw)] p-5">
                <h2 id={titleId} className="text-base font-semibold text-text">
                    {t('settings:deactivateMemberDialog.title', { name: member.user.name })}
                </h2>

                {member.isLastActiveOwner ? (
                    <>
                        <p id={descriptionId} className="mt-2 text-sm text-text-2">
                            {t('settings:deactivateMemberDialog.lastOwnerWarning')}
                        </p>
                        <div className="mt-5 flex justify-end">
                            <Button onClick={onClose}>{t('settings:deactivateMemberDialog.close')}</Button>
                        </div>
                    </>
                ) : (
                    <>
                        <p id={descriptionId} className="mt-2 text-sm text-text-2">
                            {hasOpenRecords
                                ? t('settings:deactivateMemberDialog.withOpenRecords', {
                                      name: member.user.name,
                                      summary: summarize(member.openRecords, t),
                                  })
                                : t('settings:deactivateMemberDialog.withoutOpenRecords', { name: member.user.name })}
                        </p>

                        {generalError && (
                            <p role="alert" className="mt-3 rounded-md bg-danger-tint px-3 py-2 text-sm text-danger">
                                {generalError}
                            </p>
                        )}

                        {hasOpenRecords && (
                            <div className="mt-4">
                                <Field label={t('settings:deactivateMemberDialog.newOwnerLabel')} error={errors.new_owner_user_id}>
                                    {(control) => (
                                        <select
                                            {...control}
                                            className={`${controlClass} mt-1`}
                                            value={newOwnerUserId}
                                            onChange={(event) => setNewOwnerUserId(event.target.value)}
                                        >
                                            <option value="">{t('settings:deactivateMemberDialog.chooseMember')}</option>
                                            {reassignOptions.map((option) => (
                                                <option key={option.id} value={option.id}>
                                                    {option.name}
                                                </option>
                                            ))}
                                        </select>
                                    )}
                                </Field>
                            </div>
                        )}

                        <div className="mt-5 flex flex-wrap justify-end gap-2">
                            {/* Audit de accesibilitate (P1, pct. 3) — `aria-disabled`, NU
                                `disabled` nativ, cât `processing` e adevărat: dezactivarea
                                nativă a butonului care tocmai a primit focus (clic) îl scoate
                                din arborele focalizabil, iar focusul cade pe `<body>`, fără
                                nicio veste pentru un cititor de ecran. */}
                            <Button aria-disabled={processing ? true : undefined} onClick={requestClose}>
                                {t('settings:deactivateMemberDialog.cancel')}
                            </Button>
                            {hasOpenRecords && (
                                <Button
                                    variant="primary"
                                    disabled={newOwnerUserId === ''}
                                    aria-disabled={processing || newOwnerUserId === '' ? true : undefined}
                                    onClick={() => {
                                        if (processing || newOwnerUserId === '') {
                                            return;
                                        }

                                        setPendingAction('reassign');
                                        onReassignAndDeactivate(newOwnerUserId);
                                    }}
                                >
                                    {processing && pendingAction === 'reassign'
                                        ? t('settings:deactivateMemberDialog.reassigning')
                                        : t('settings:deactivateMemberDialog.reassignAndDeactivate')}
                                </Button>
                            )}
                            <Button
                                variant="danger"
                                aria-disabled={processing ? true : undefined}
                                onClick={() => {
                                    if (processing) {
                                        return;
                                    }

                                    setPendingAction('anyway');
                                    onDeactivateAnyway();
                                }}
                            >
                                {processing && pendingAction === 'anyway'
                                    ? t('settings:deactivateMemberDialog.deactivating')
                                    : hasOpenRecords
                                      ? t('settings:deactivateMemberDialog.deactivateAnyway')
                                      : t('settings:deactivateMemberDialog.deactivate')}
                            </Button>
                        </div>
                    </>
                )}
            </div>
        </dialog>
    );
}
