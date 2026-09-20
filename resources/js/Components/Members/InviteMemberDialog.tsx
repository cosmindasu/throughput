import { useForm } from '@inertiajs/react';
import { useEffect, useId, useRef, type FormEvent } from 'react';
import Button from '@/Components/Button';
import Field, { controlClass } from '@/Components/Form/Field';

interface InviteMemberDialogProps {
    open: boolean;
    /**
     * `/{workspace}/settings/members`, construit de pagină din propul comun `workspace`.
     *
     * Primit ca prop, NU recompus aici: rutele ecranului stau sub prefixul `{workspace}`
     * (`routes/web.php`), proiectul nu are Ziggy, iar o cale care începe cu `/` e rezolvată
     * de Inertia față de RĂDĂCINA originii — `/settings/members/invite` ar fi lovit o rută
     * inexistentă. Un singur loc compune calea, ca pagina și dialogul să nu poată diverge.
     */
    basePath: string;
    /** BR-TEN-02 — calculat server-side: un Manager nu primește niciodată „Owner" aici. */
    invitableRoles: string[];
    onClose: () => void;
    /** Focalizat după succes de apelant (rândul nou apare în tabel, declanșatorul rămâne). */
    onInvited: (email: string) => void;
}

/**
 * US-TEN-01, §6.4 — „trimit o invitație către «coleg@exemplu.com» cu rolul «Agent»".
 *
 * Pe `<dialog>` nativ, ca `DeactivateMemberDialog` (aceeași pagină, același tipar de
 * focus-trap și de `cancel`). Formular propriu, cu `useForm`, nu `router.post`: câmpurile
 * au erori de VALIDARE per câmp (`email`, `role`), iar `useForm` le leagă direct de
 * `Field`, care le expune prin `aria-describedby`/`aria-invalid`.
 *
 * Audit de accesibilitate (P1, regulile din `.ai/rules/frontend.md`):
 *  - dialogul se închide DOAR în `onSuccess` — la 422 rămâne deschis, cu eroarea lângă
 *    câmpul ei; `onFinish` ar fi rulat și pe eroare;
 *  - `aria-disabled`, nu `disabled` nativ, pe butonul care tocmai a primit clic —
 *    `disabled` îl scoate din arborele focalizabil și focusul cade pe `<body>`, chiar și
 *    într-un `<dialog>` modal;
 *  - Esc (`cancel`) e oprit cât o cerere e în curs, simetric cu butonul „Cancel".
 */
export default function InviteMemberDialog({
    open,
    basePath,
    invitableRoles,
    onClose,
    onInvited,
}: InviteMemberDialogProps) {
    const ref = useRef<HTMLDialogElement>(null);
    const titleId = useId();
    const descriptionId = useId();

    const { data, setData, post, processing, errors, reset, clearErrors } = useForm({
        email: '',
        // Implicitul cel mai puțin periculos dintre rolurile pe care oricine le poate
        // acorda: un rol prea larg, ales din neatenție, se corectează abia după ce
        // invitatul a intrat deja cu el.
        role: invitableRoles.includes('Viewer') ? 'Viewer' : (invitableRoles[0] ?? ''),
    });

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

        clearErrors();
        reset('email');
        onClose();
    };

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();

        const email = data.email;

        post(`${basePath}/invite`, {
            preserveScroll: true,
            onSuccess: () => {
                reset('email');
                onInvited(email);
            },
        });
    };

    return (
        <dialog
            ref={ref}
            aria-labelledby={titleId}
            aria-describedby={descriptionId}
            onClose={onClose}
            className="m-auto rounded-lg border border-border bg-overlay p-0 text-text backdrop:bg-scrim"
        >
            <form onSubmit={submit} className="w-[min(30rem,90vw)] p-5" noValidate>
                <h2 id={titleId} className="text-base font-semibold text-text">
                    Invite a member
                </h2>

                <p id={descriptionId} className="mt-2 text-sm text-text-2">
                    They get an email with a link that is valid for 7 days. Nothing changes in this workspace
                    until they accept it.
                </p>

                <div className="mt-4 flex flex-col gap-4">
                    <Field label="Email address" error={errors.email} required>
                        {(control) => (
                            <input
                                {...control}
                                type="email"
                                autoComplete="email"
                                className={controlClass}
                                placeholder="colleague@example.com"
                                value={data.email}
                                onChange={(event) => setData('email', event.target.value)}
                            />
                        )}
                    </Field>

                    <Field
                        label="Role"
                        error={errors.role}
                        hint="Roles can be changed later from this list."
                        required
                    >
                        {(control) => (
                            <select
                                {...control}
                                className={controlClass}
                                value={data.role}
                                onChange={(event) => setData('role', event.target.value)}
                            >
                                {invitableRoles.map((role) => (
                                    <option key={role} value={role}>
                                        {role}
                                    </option>
                                ))}
                            </select>
                        )}
                    </Field>
                </div>

                <div className="mt-5 flex flex-wrap justify-end gap-2">
                    <Button type="button" aria-disabled={processing ? true : undefined} onClick={requestClose}>
                        Cancel
                    </Button>
                    <Button type="submit" variant="primary" aria-disabled={processing ? true : undefined}>
                        {processing ? 'Sending…' : 'Send invitation'}
                    </Button>
                </div>
            </form>
        </dialog>
    );
}
