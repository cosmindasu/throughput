import { adrUrl } from '@/help/adr';
import type { HelpTopic } from '@/help/types';

/**
 * `Settings/Members/Index` — specs.md §6.4/§6.4.1 (US-TEN-02/03, BR-TEN-01…06,
 * FR-TEN-04), ADR-011. `MembersController`, `MembershipPolicy`, `DeactivateMemberDialog`.
 */
const membersTopic: HelpTopic = {
    id: 'members',
    title: 'Members',
    whatIsThis:
        'Everyone with access to this workspace — their role, when they joined, and whether they still have access. This is where an Owner or Manager revokes access for someone who leaves.',
    whatCanYouDo: [
        'See every member\'s role, status and join date, and who deactivated a former member and when.',
        'Press "Deactivate member" to revoke someone\'s access immediately.',
        'When they own open deals or active orders, choose "Reassign and deactivate" and pick a new owner, or "Deactivate anyway" and sort it out later from "Unassigned".',
    ],
    rules: [
        'Deactivating someone is never blocked by what they own — revoking access comes first, always. The one absolute exception is the last active Owner: a workspace can\'t be left without one, and there is no button to force it anyway.',
        'A Manager can deactivate an Agent or Viewer, but not an Owner — only another Owner can do that.',
        'Deactivating is not a delete: the member row stays, with their role and join date, for an auditable history — their name keeps showing on everything they created, with a "(deactivated)" mark next to it.',
        'A deactivated member disappears from the workspace switcher and from every "assign to…" list — they can\'t receive new work, but nothing they already did is rewritten.',
        'In the public demo, "Deactivate member" is hidden entirely: the demo accounts are shared logins every visitor uses to log in as Owner, Manager, Agent or Viewer.',
    ],
    howItsBuilt: {
        summary:
            'Access is revoked on the very next request, not on next login: the workspace switcher and the RLS policy on `memberships` only ever see rows with `status = active`, so a deactivated membership simply stops resolving — no session to invalidate, no token to revoke. Choosing "Reassign and deactivate" runs the exact same bulk-operation mechanism as any other bulk reassignment (a planner job, chunked and queued), just three times in one request — accounts, open deals, active orders — tied together by one `group_id` so the confirmation dialog\'s "12 open deals and 3 active orders" reads as a single operation, not three unrelated ones.',
        adr: {
            id: 'ADR-011',
            title: 'Deactivating a member is never blocked by the records they own',
            url: adrUrl('ADR-011', 'dezactivare-membru-fara-blocare'),
        },
    },
};

export default membersTopic;
