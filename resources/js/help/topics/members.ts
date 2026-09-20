import { adrUrl } from '@/help/adr';
import type { HelpTopic } from '@/help/types';

/**
 * `Settings/Members/Index` — specs.md §6.4/§6.4.1 (US-TEN-01/02/03, BR-TEN-01…07,
 * FR-TEN-04), ADR-011. `MembersController`, `InvitationController`, `MembershipPolicy`,
 * `InviteMemberDialog`, `ChangeRoleDialog`, `DeactivateMemberDialog`.
 */
const membersTopic: HelpTopic = {
    id: 'members',
    title: 'Members & invitations',
    whatIsThis:
        'Everyone with access to this workspace — their role, when they joined, and whether they still have access. This is where an Owner or Manager invites a colleague, changes someone\'s role, and revokes access for someone who leaves.',
    whatCanYouDo: [
        'Invite a colleague by email address and pick the role they get. They have 7 days to accept; nothing changes here until they do.',
        'Resend an invitation link, or revoke one that was sent by mistake — the old link stops working either way.',
        'Change a member\'s role. It takes effect on their very next request, not on their next login.',
        'See every member\'s role, status and join date, and who deactivated a former member and when.',
        'Press "Deactivate member" to revoke someone\'s access immediately.',
        'When they own open deals or active orders, choose "Reassign and deactivate" and pick a new owner, or "Deactivate anyway" and sort it out later from "Unassigned".',
    ],
    rules: [
        'Only an Owner or a Manager can invite. A Manager can invite and manage Agents and Viewers, but cannot invite an Owner, promote anyone to Owner, or touch an existing Owner — in either direction.',
        'A workspace always keeps at least one active Owner. The last one cannot be demoted or deactivated, and there is no button to force it: transfer ownership first.',
        'You cannot change your own role or deactivate yourself from this screen — ask another Owner or Manager.',
        'Deactivating someone is never blocked by what they own — revoking access comes first, always.',
        'Deactivating is not a delete: the member row stays, with their role and join date, for an auditable history — their name keeps showing on everything they created, with a "(deactivated)" mark next to it. Revoking an invitation that was never accepted does delete it: there is no history to keep yet.',
        'A deactivated member disappears from the workspace switcher and from every "assign to…" list — they can\'t receive new work, but nothing they already did is rewritten. Invite them back and the same row is reused.',
        'In the public demo, both "Deactivate member" and "Change role" are hidden entirely: the demo accounts are shared logins every visitor uses to log in as Owner, Manager, Agent or Viewer, so demoting or deactivating one would break the next visitor\'s session until the nightly reset. Inviting, resending and revoking an invitation all still work.',
    ],
    howItsBuilt: {
        summary:
            'An invitation is not a separate table — it is a membership row with status "pending" and a hashed acceptance token, on columns that have been in the schema since week one. Only the hash is stored: the link in the email is the single place the real token ever exists, and the demo\'s "Sent Emails" journal redacts it, exactly like a password-reset link. That email is also the one flow in the whole product that sends to an address a visitor typed, which is why the demo email interceptor was built a phase earlier than this screen. Access is revoked (and granted) on the very next request, not on next login: the one query that resolves "which workspaces am I in" only ever returns rows with status "active", so a pending or deactivated membership simply stops resolving for the workspace switcher, for the request that sets the tenant, and for API tokens alike — no session to invalidate, no token to revoke. Choosing "Reassign and deactivate" runs the same bulk-operation mechanism as any other bulk reassignment, three times in one request — accounts, open deals, active orders — tied together by one group id so it reads as a single operation.',
        adr: {
            id: 'ADR-011',
            title: 'Deactivating a member is not blocked by the records they own',
            url: adrUrl('ADR-011', 'dezactivare-membru-fara-blocare'),
        },
    },
};

export default membersTopic;
