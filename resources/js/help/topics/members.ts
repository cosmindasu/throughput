import { adrUrl } from '@/help/adr';
import type { HelpTopicDefinition } from '@/help/types';

/**
 * `Settings/Members/Index` — specs.md §6.4/§6.4.1 (US-TEN-01/02/03, BR-TEN-01…07,
 * FR-TEN-04), ADR-011. `MembersController`, `InvitationController`, `MembershipPolicy`,
 * `InviteMemberDialog`, `ChangeRoleDialog`, `DeactivateMemberDialog`.
 */
const membersTopic: HelpTopicDefinition = {
    id: 'members',
    adr: {
        id: 'ADR-011',
        title: 'Deactivating a member is not blocked by the records they own',
        url: adrUrl('ADR-011', 'dezactivare-membru-fara-blocare'),
    },
};

export default membersTopic;
