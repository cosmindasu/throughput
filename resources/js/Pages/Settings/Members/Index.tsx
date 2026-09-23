import { Head, router, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import type { TFunction } from 'i18next';
import Button from '@/Components/Button';
import ChangeRoleDialog from '@/Components/Members/ChangeRoleDialog';
import DeactivateMemberDialog from '@/Components/Members/DeactivateMemberDialog';
import InviteMemberDialog from '@/Components/Members/InviteMemberDialog';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import { useLocale } from '@/hooks/useLocale';
import { roleLabel } from '@/lib/roles';
import AppLayout from '@/Layouts/AppLayout';
import { formatDate as formatDateLocale } from '@/lib/format';
import type { AppLocale } from '@/lib/i18n';
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

/**
 * Starea reală a unei invitații e „invited" sau „expired", nu „pending": ultimul e
 * numele coloanei din bază, nu o stare pe care un Owner o recunoaște.
 */
function statusLabel(member: MembershipRow, t: TFunction<'settings'>): string {
    if (member.status !== 'pending') {
        return t(`settings:members.status.${member.status}`);
    }

    return t(member.invitation?.isExpired ? 'settings:members.status.expired' : 'settings:members.status.invited');
}

/** `—` pentru `null` — `lib/format.ts` nu tratează cazul lipsă, doar Intl valid. */
function formatMemberDate(value: string | null, locale: AppLocale): string {
    return value ? formatDateLocale(value, locale) : '—';
}

/**
 * FR-I18N-06 — `member.user.name` e conținut de utilizator, interpolat, NICIODATĂ tradus.
 * Tiparele de pluralizare (`record${total === 1 ? '' : 's'}` × 2) sunt motorul CLDR al
 * i18next (`deactivatedReassigning`/`deactivatedNeedsOwner`), nu `=== 1 ? … : …` scris de
 * mână — ADR-022 le documenta explicit ca findind două din cele trei tipare cunoscute.
 */
function outcomeMessage(member: MembershipRow, reassigned: boolean, t: TFunction<'settings'>): string {
    const total = member.openRecords.total;
    const name = member.user.name;

    if (reassigned) {
        // Practic neatins de pe această pagină — „Reassign and deactivate" redirecționează
        // spre `/bulk/groups/{id}` (altă componentă Inertia), deci `onSuccess` de aici nu
        // mai apucă să randeze. Păstrat pentru corectitudine și pentru cazul în care
        // redirectul se schimbă mai târziu.
        return t('settings:members.outcomes.deactivatedReassigning', { count: total, name });
    }

    if (total > 0) {
        return t('settings:members.outcomes.deactivatedNeedsOwner', { count: total, name });
    }

    return t('settings:members.outcomes.deactivated', { name });
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
    const { t } = useTranslation('settings');
    const locale = useLocale();

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
                    setOutcome(outcomeMessage(member, reassign, t));
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
                    setOutcome(t('settings:members.outcomes.roleChanged', { name: member.user.name, role }));
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
                onSuccess: () =>
                    setOutcome(t('settings:members.outcomes.invitationResent', { email: member.user.email })),
            },
        );
    };

    const revokeInvitation = (member: MembershipRow) => {
        router.delete(`${basePath}/${member.id}`, {
            preserveScroll: true,
            onSuccess: () =>
                setOutcome(t('settings:members.outcomes.invitationRevoked', { email: member.user.email })),
        });
    };

    return (
        <>
            {/* SC 2.4.2 (Page Titled) — vezi nota din `Unassigned/Index.tsx`. */}
            <Head title={t('settings:members.title')} />

            <PageHeader
                title={t('settings:members.title')}
                description={t('settings:members.description')}
                actions={
                    // FR-RBAC-01 — absent, nu dezactivat, pentru Agent și Viewer
                    // (`members.invite` e doar la Owner/Manager, matricea §7.4).
                    can.invite ? (
                        <Button variant="primary" onClick={() => setInviteOpen(true)}>
                            {t('settings:members.inviteButton')}
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
                    <caption className="sr-only">{t('settings:members.tableCaption')}</caption>
                    <thead className="border-b border-border bg-surface text-text-2">
                        <tr>
                            <th scope="col" className="px-4 py-2 font-medium">{t('settings:members.columns.name')}</th>
                            <th scope="col" className="px-4 py-2 font-medium">{t('settings:members.columns.role')}</th>
                            <th scope="col" className="px-4 py-2 font-medium">{t('settings:members.columns.status')}</th>
                            <th scope="col" className="px-4 py-2 font-medium">{t('settings:members.columns.joined')}</th>
                            <th scope="col" className="px-4 py-2 font-medium">{t('settings:members.columns.deactivated')}</th>
                            <th scope="col" className="px-4 py-2 font-medium">
                                <span className="sr-only">{t('settings:members.columns.actions')}</span>
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
                                <td className="px-4 py-3 text-text-2">{roleLabel(t, member.role) ?? '—'}</td>
                                <td className="px-4 py-3">
                                    <StatusBadge tone={statusTone(member.status)}>{statusLabel(member, t)}</StatusBadge>
                                </td>
                                <td className="px-4 py-3 text-text-2">
                                    {member.invitation ? (
                                        <>
                                            {member.invitation.isExpired
                                                ? t('settings:members.invitationExpired', {
                                                      date: formatMemberDate(member.invitation.expiresAt, locale),
                                                  })
                                                : t('settings:members.invitationPending', {
                                                      invitedDate: formatMemberDate(member.invitation.invitedAt, locale),
                                                      expiresDate: formatMemberDate(member.invitation.expiresAt, locale),
                                                  })}
                                        </>
                                    ) : (
                                        formatMemberDate(member.joinedAt, locale)
                                    )}
                                </td>
                                <td className="px-4 py-3 text-text-2">
                                    {member.deactivatedAt ? (
                                        <>
                                            {formatMemberDate(member.deactivatedAt, locale)}
                                            {member.deactivatedBy && (
                                                <> {t('settings:members.deactivatedBy', { name: member.deactivatedBy.name })}</>
                                            )}
                                        </>
                                    ) : (
                                        '—'
                                    )}
                                </td>
                                <td className="px-4 py-3">
                                    <div className="flex flex-wrap justify-end gap-2">
                                        {/* Audit de accesibilitate (SC 2.5.3, Label in Name) — `aria-label`
                                            ÎNLOCUIA textul vizibil în loc să-l prefixeze. Tiparul corect
                                            (`.ai/rules/frontend.md`, „Focusul nu se pierde niciodată pe
                                            `<body>`"): textul vizibil rămâne primul, discriminatorul e un
                                            sufix `sr-only`. */}
                                        {member.canManageInvitation && (
                                            <>
                                                <Button onClick={() => resendInvitation(member)}>
                                                    {t('settings:members.resendButton')}
                                                    <span className="sr-only">
                                                        {' '}
                                                        {t('settings:members.invitationSrLabel', { email: member.user.email })}
                                                    </span>
                                                </Button>
                                                <Button variant="danger" onClick={() => revokeInvitation(member)}>
                                                    {t('settings:members.revokeButton')}
                                                    <span className="sr-only">
                                                        {' '}
                                                        {t('settings:members.invitationSrLabel', { email: member.user.email })}
                                                    </span>
                                                </Button>
                                            </>
                                        )}
                                        {member.canUpdateRole && (
                                            <Button
                                                onClick={() => {
                                                    setErrors({});
                                                    setRoleTarget(member);
                                                }}
                                            >
                                                {t('settings:members.changeRoleButton')}
                                                <span className="sr-only"> {member.user.name}</span>
                                            </Button>
                                        )}
                                        {member.canDeactivate && (
                                            <Button variant="danger" onClick={() => openDialogFor(member)}>
                                                {t('settings:members.deactivateButton')}
                                                <span className="sr-only"> {member.user.name}</span>
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
                        setOutcome(t('settings:members.outcomes.invited', { email }));
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
