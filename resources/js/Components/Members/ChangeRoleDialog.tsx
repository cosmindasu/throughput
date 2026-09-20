import { useEffect, useId, useRef, useState } from 'react';
import Button from '@/Components/Button';
import Field, { controlClass } from '@/Components/Form/Field';
import type { MembershipRow } from '@/types/generated';

interface ChangeRoleDialogProps {
    open: boolean;
    member: MembershipRow | null;
    processing: boolean;
    /** `page.props.errors` din cererea eșuată curentă — cheia `role` vine din Policy. */
    errors: Record<string, string>;
    onClose: () => void;
    onConfirm: (role: string) => void;
}

/**
 * US-TEN-02 — „schimb rolul unui Manager în Viewer; modificarea e efectivă imediat".
 *
 * Confirmare explicită, nu un `<select>` care salvează la `onChange` direct din rând:
 * schimbarea de rol e ireversibilă din perspectiva accesului deja exercitat, iar un
 * dropdown care se aplică la prima apăsare de tastă (navigarea cu săgeți într-un `<select>`
 * schimbă valoarea în multe browsere) ar retrograda un coleg din greșeală.
 *
 * `key={member.id}` la apelant — la schimbarea rândului țintă componenta se remontează,
 * deci `role` pornește de la rolul REAL al noului rând, fără un `useEffect` care ar seta
 * starea sincron (regula `react-hooks/set-state-in-effect`). La EROARE cheia rămâne
 * aceeași, deci selecția utilizatorului supraviețuiește refuzului.
 *
 * BR-TEN-01 — mesajul „A workspace needs at least one Owner" vine din
 * `MembershipPolicy::updateRole()`, prin `errors.role`: blocarea e server-side, aici se
 * doar AFIȘEAZĂ. Ascunderea opțiunii în interfață n-ar fi fost o blocare.
 */
export default function ChangeRoleDialog({
    open,
    member,
    processing,
    errors,
    onClose,
    onConfirm,
}: ChangeRoleDialogProps) {
    const ref = useRef<HTMLDialogElement>(null);
    const titleId = useId();
    const descriptionId = useId();
    const [role, setRole] = useState(member?.role ?? '');

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
            <dialog
                ref={ref}
                onClose={onClose}
                className="m-auto rounded-lg border border-border bg-overlay p-0 text-text backdrop:bg-scrim"
            />
        );
    }

    const unchanged = role === member.role;

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
                    Change {member.user.name}&rsquo;s role
                </h2>

                <p id={descriptionId} className="mt-2 text-sm text-text-2">
                    They are {member.role ?? 'unassigned'} today. A new role takes effect on their very next
                    request — they do not need to sign out and back in.
                </p>

                <div className="mt-4">
                    <Field label="New role" error={errors.role}>
                        {(control) => (
                            <select
                                {...control}
                                className={controlClass}
                                value={role}
                                onChange={(event) => setRole(event.target.value)}
                            >
                                {member.assignableRoles.map((option) => (
                                    <option key={option} value={option}>
                                        {option}
                                    </option>
                                ))}
                            </select>
                        )}
                    </Field>
                </div>

                <div className="mt-5 flex flex-wrap justify-end gap-2">
                    {/* `aria-disabled`, nu `disabled` nativ, cât cererea e în curs — vezi
                        `DeactivateMemberDialog` și `.ai/rules/frontend.md`. */}
                    <Button aria-disabled={processing ? true : undefined} onClick={requestClose}>
                        Cancel
                    </Button>
                    <Button
                        variant="primary"
                        aria-disabled={processing || unchanged ? true : undefined}
                        onClick={() => {
                            if (processing || unchanged) {
                                return;
                            }

                            onConfirm(role);
                        }}
                    >
                        {processing ? 'Saving…' : 'Change role'}
                    </Button>
                </div>
            </div>
        </dialog>
    );
}
