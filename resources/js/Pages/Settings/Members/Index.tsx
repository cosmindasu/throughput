import { router, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState, type ReactNode } from 'react';
import Button from '@/Components/Button';
import DeactivateMemberDialog from '@/Components/Members/DeactivateMemberDialog';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import AppLayout from '@/Layouts/AppLayout';
import type { MembersIndexPageProps, MembershipRow } from '@/types/generated';

function statusTone(status: MembershipRow['status']) {
    if (status === 'active') {
        return 'success' as const;
    }

    if (status === 'pending') {
        return 'neutral' as const;
    }

    return 'danger' as const;
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
 * Settings → Members (§6.4, §6.4.1, US-TEN-02/03) — lista membrilor tenantului, cu
 * dezactivare (ADR-011): rândul RĂMÂNE la dezactivare (BR-TEN-04), doar statusul și
 * „deactivated by/at" se schimbă — istoricul auditabil, nu un `DELETE`.
 */
export default function MembersIndex() {
    const { members, activeMembers } = usePage<MembersIndexPageProps>().props;
    const [target, setTarget] = useState<MembershipRow | null>(null);
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    // Audit de accesibilitate (P1, pct. 4) — mesajul de succes, focalizat explicit după
    // închiderea dialogului: rândul își pierde butonul „Deactivate member" (`canDeactivate`
    // devine `false`), iar `<dialog>` n-are unde să readucă focusul singur.
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

    const submit = (reassign: boolean, newOwnerUserId?: string) => {
        if (!target) {
            return;
        }

        const member = target;
        setProcessing(true);
        setErrors({});

        router.post(
            `/settings/members/${member.id}/deactivate`,
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

    return (
        <>
            <PageHeader
                title="Members"
                description="Everyone with access to this workspace, and their role."
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
                                    <StatusBadge tone={statusTone(member.status)}>{member.status}</StatusBadge>
                                </td>
                                <td className="px-4 py-3 text-text-2">{formatDate(member.joinedAt)}</td>
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
                                <td className="px-4 py-3 text-right">
                                    {member.canDeactivate && (
                                        <Button
                                            variant="danger"
                                            aria-label={`Deactivate ${member.user.name}`}
                                            onClick={() => openDialogFor(member)}
                                        >
                                            Deactivate member
                                        </Button>
                                    )}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

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
