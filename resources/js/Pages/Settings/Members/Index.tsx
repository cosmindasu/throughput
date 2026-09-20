import { router, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState, type ReactNode } from 'react';
import Button from '@/Components/Button';
import ChangeRoleDialog from '@/Components/Members/ChangeRoleDialog';
import DeactivateMemberDialog from '@/Components/Members/DeactivateMemberDialog';
import InviteMemberDialog from '@/Components/Members/InviteMemberDialog';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import AppLayout from '@/Layouts/AppLayout';
import type { MembershipRow, MembersIndexPageProps } from '@/types/generated';

function statusTone(status: MembershipRow['status']) {
    if (status === 'active') {
        return 'success' as const;
    }

    if (status === 'pending') {
        return 'info' as const;
    }

    return 'danger' as const;
}

function statusLabel(member: MembershipRow): string {
    if (member.status !== 'pending') {
        return member.status;
    }

    // Starea reală a unei invitații e „invited" sau „expired", nu „pending": ultimul e
    // numele coloanei din bază, nu o stare pe care un Owner o recunoaște.
    return member.invitation?.isExpired ? 'expired' : 'invited';
}

function formatDate(value: string | null): string {
    if (!value) {
        return '—';
    }

    return new Date(value).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
}

function outcomeMessage(member: MembershipRow, reassigned: boolean): string {
    const total = member.openRecords.total;

    if (reassigned) {
        // Practic neatins de pe această pagină — „Reassign and deactivate" redirecționează
        // spre `/bulk/groups/{id}` (altă componentă Inertia), deci `onSuccess` de aici nu
        // mai apucă să randeze. Păstrat pentru corectitudine și pentru cazul în care
        // redirectul se schimbă mai târziu.
        return `${member.user.name} was deactivated; reassigning ${total} record${total === 1 ? '' : 's'}…`;
    }

    if (total > 0) {
        return `${member.user.name} was deactivated; ${total} record${total === 1 ? '' : 's'} need a new owner — see Unassigned.`;
    }

    return `${member.user.name} was deactivated.`;
}

/**
 * Settings → Members (§6.4, §6.4.1, US-TEN-01/02/03) — lista membrilor tenantului, cu:
 *
 *  - INVITARE (US-TEN-01): email + rol; invitatul primește un link valid 7 zile. E singurul
 *    flux din produs care trimite email către o adresă arbitrară — trece prin interceptorul
 *    construit în Faza 4 (specs.md §22.3), fără nimic de configurat aici.
 *  - SCHIMBAREA ROLULUI (US-TEN-02), cu BR-TEN-01/02 aplicate server-side.
 *  - DEZACTIVARE (US-TEN-03, ADR-011): rândul RĂMÂNE (BR-TEN-04), doar statusul și
 *    „deactivated by/at" se schimbă — istoric auditabil, nu un `DELETE`.
 *
 * Fiecare buton e condiționat de un `can` calculat SERVER-SIDE (FR-RBAC-01): un buton care
 * ar duce la 403 se citește ca „aplicație stricată", nu ca „aplicație securizată".
 */
export default function MembersIndex() {
    const { members, activeMembers, invitableRoles, can, workspace } = usePage<MembersIndexPageProps>().props;

    // Toate rutele acestui ecran sunt înregistrate sub `/{workspace}` (`routes/web.php`,
    // grupul cu prefix) — `php artisan route:list` arată `{workspace}/settings/members/…`.
    // Pe server, `URL::defaults(['workspace' => …])` (ADR-002, `ResolveWorkspace`) pune
    // segmentul automat în orice `route()`; pe client NU există echivalent — proiectul nu
    // are Ziggy (vezi `AppLayout.tsx`), iar o cale care începe cu `/` e rezolvată de Inertia
    // față de RĂDĂCINA originii, nu față de pagina curentă. Deci `/settings/members/…` ar fi
    // lovit o rută inexistentă. Convenția, aceeași ca în `Settings/Billing/Index.tsx`,
    // `Deals/Create.tsx`, `Orders/Show.tsx`: slug-ul se pune explicit, din propul comun.
    //
    // Defect găsit de lotul F la review, nu de teste: Pest lovește serverul cu URL-ul
    // construit în PHP, deci nu trece niciodată prin această linie, iar în demo
    // `EnsureDemoModeGuardrails` oprește oricum dezactivarea, mascând simptomul.
    const basePath = `/${workspace?.slug ?? ''}/settings/members`;
    const [target, setTarget] = useState<MembershipRow | null>(null);
    const [roleTarget, setRoleTarget] = useState<MembershipRow | null>(null);
    const [inviteOpen, setInviteOpen] = useState(false);
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    // Audit de accesibilitate (P1, pct. 4) — mesajul de succes, focalizat explicit după
    // închiderea dialogului: rândul își pierde butoanele (`canDeactivate` devine `false`,
    // o invitație revocată dispare cu totul), iar `<dialog>` n-are unde să readucă focusul.
    const [outcome, setOutcome] = useState<string | null>(null);
    const statusRef = useRef<HTMLDivElement>(null);

    useEffect(() => {
        if (outcome) {
            statusRef.current?.focus();
        }
    }, [outcome]);

    const openDialogFor = (member: MembershipRow) => {
        setErrors({});
        setTarget(member);
    };

    const close = () => {
        if (processing) {
            return;
        }

        setTarget(null);
        setErrors({});
    };

    const closeRoleDialog = () => {
        if (processing) {
            return;
        }

        setRoleTarget(null);
        setErrors({});
    };

    const submit = (reassign: boolean, newOwnerUserId?: string) => {
        if (!target) {
            return;
        }

        const member = target;
        setProcessing(true);
        setErrors({});

        router.post(
            `${basePath}/${member.id}/deactivate`,
            reassign ? { reassign: true, new_owner_user_id: newOwnerUserId } : { reassign: false },
            {
                preserveScroll: true,
                // Audit de accesibilitate (P1, pct. 1) — închidere DOAR la succes: la o
                // eroare (422 de câmp, sau `errors.deactivate` din refuzul de Policy —
                // vezi `MembersController::deactivate()`), dialogul rămâne deschis, cu
                // eroarea legată de câmpul ei sau afișată ca alertă generică.
                onSuccess: () => {
                    setProcessing(false);
                    setTarget(null);
                    setOutcome(outcomeMessage(member, reassign));
                },
                onError: (pageErrors) => {
                    setProcessing(false);
                    setErrors(pageErrors);
                },
            },
        );
    };

    const submitRoleChange = (role: string) => {
        if (!roleTarget) {
            return;
        }

        const member = roleTarget;
        setProcessing(true);
        setErrors({});

        router.patch(
            `${basePath}/${member.id}/role`,
            { role },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setProcessing(false);
                    setRoleTarget(null);
                    setOutcome(`${member.user.name} is now ${role}.`);
                },
                onError: (pageErrors) => {
                    setProcessing(false);
                    setErrors(pageErrors);
                },
            },
        );
    };

    const resendInvitation = (member: MembershipRow) => {
        router.post(
            `${basePath}/${member.id}/resend`,
            {},
            {
                preserveScroll: true,
                onSuccess: () => setOutcome(`A new invitation link was sent to ${member.user.email}.`),
            },
        );
    };

    const revokeInvitation = (member: MembershipRow) => {
        router.delete(`${basePath}/${member.id}`, {
            preserveScroll: true,
            onSuccess: () => setOutcome(`The invitation to ${member.user.email} was revoked.`),
        });
    };

    return (
        <>
            <PageHeader
                title="Members"
                description="Everyone with access to this workspace, and their role."
                actions={
                    // FR-RBAC-01 — absent, nu dezactivat, pentru Agent și Viewer
                    // (`members.invite` e doar la Owner/Manager, matricea §7.4).
                    can.invite ? (
                        <Button variant="primary" onClick={() => setInviteOpen(true)}>
                            Invite member
                        </Button>
                    ) : undefined
                }
            />

            {outcome && (
                <div
                    ref={statusRef}
                    role="status"
                    tabIndex={-1}
                    className="mt-4 rounded-md bg-success-tint px-3 py-2 text-sm text-success focus:outline-none"
                >
                    {outcome}
                </div>
            )}

            <div className="mt-6 overflow-x-auto rounded-lg border border-border">
                <table className="w-full text-left text-sm">
                    <caption className="sr-only">Workspace members and their roles</caption>
                    <thead className="border-b border-border bg-surface text-text-2">
                        <tr>
                            <th scope="col" className="px-4 py-2 font-medium">Name</th>
                            <th scope="col" className="px-4 py-2 font-medium">Role</th>
                            <th scope="col" className="px-4 py-2 font-medium">Status</th>
                            <th scope="col" className="px-4 py-2 font-medium">Joined</th>
                            <th scope="col" className="px-4 py-2 font-medium">Deactivated</th>
                            <th scope="col" className="px-4 py-2 font-medium">
                                <span className="sr-only">Actions</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        {members.map((member) => (
                            <tr key={member.id} className="border-b border-border-soft last:border-0">
                                <td className="px-4 py-3">
                                    <div className="font-medium text-text">{member.user.name}</div>
                                    <div className="text-text-2">{member.user.email}</div>
                                </td>
                                <td className="px-4 py-3 text-text-2">{member.role ?? '—'}</td>
                                <td className="px-4 py-3">
                                    <StatusBadge tone={statusTone(member.status)}>{statusLabel(member)}</StatusBadge>
                                </td>
                                <td className="px-4 py-3 text-text-2">
                                    {member.invitation ? (
                                        <>
                                            {member.invitation.isExpired
                                                ? `Invitation expired ${formatDate(member.invitation.expiresAt)}`
                                                : `Invited ${formatDate(member.invitation.invitedAt)} · expires ${formatDate(member.invitation.expiresAt)}`}
                                        </>
                                    ) : (
                                        formatDate(member.joinedAt)
                                    )}
                                </td>
                                <td className="px-4 py-3 text-text-2">
                                    {member.deactivatedAt ? (
                                        <>
                                            {formatDate(member.deactivatedAt)}
                                            {member.deactivatedBy && <> by {member.deactivatedBy.name}</>}
                                        </>
                                    ) : (
                                        '—'
                                    )}
                                </td>
                                <td className="px-4 py-3">
                                    <div className="flex flex-wrap justify-end gap-2">
                                        {member.canManageInvitation && (
                                            <>
                                                <Button
                                                    aria-label={`Resend the invitation to ${member.user.email}`}
                                                    onClick={() => resendInvitation(member)}
                                                >
                                                    Resend
                                                </Button>
                                                <Button
                                                    variant="danger"
                                                    aria-label={`Revoke the invitation to ${member.user.email}`}
                                                    onClick={() => revokeInvitation(member)}
                                                >
                                                    Revoke
                                                </Button>
                                            </>
                                        )}
                                        {member.canUpdateRole && (
                                            <Button
                                                aria-label={`Change ${member.user.name}'s role`}
                                                onClick={() => {
                                                    setErrors({});
                                                    setRoleTarget(member);
                                                }}
                                            >
                                                Change role
                                            </Button>
                                        )}
                                        {member.canDeactivate && (
                                            <Button
                                                variant="danger"
                                                aria-label={`Deactivate ${member.user.name}`}
                                                onClick={() => openDialogFor(member)}
                                            >
                                                Deactivate member
                                            </Button>
                                        )}
                                    </div>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            {can.invite && (
                <InviteMemberDialog
                    open={inviteOpen}
                    basePath={basePath}
                    invitableRoles={invitableRoles}
                    onClose={() => setInviteOpen(false)}
                    onInvited={(email) => {
                        setInviteOpen(false);
                        setOutcome(`Invitation sent to ${email}.`);
                    }}
                />
            )}

            <ChangeRoleDialog
                key={`role-${roleTarget?.id ?? 'none'}`}
                open={roleTarget !== null}
                member={roleTarget}
                processing={processing}
                errors={errors}
                onClose={closeRoleDialog}
                onConfirm={submitRoleChange}
            />

            <DeactivateMemberDialog
                key={target?.id ?? 'none'}
                open={target !== null}
                member={target}
                activeMembers={activeMembers}
                processing={processing}
                errors={errors}
                onClose={close}
                onReassignAndDeactivate={(newOwnerUserId) => submit(true, newOwnerUserId)}
                onDeactivateAnyway={() => submit(false)}
            />
        </>
    );
}

MembersIndex.layout = (page: ReactNode) => <AppLayout>{page}</AppLayout>;
