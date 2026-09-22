import { adrUrl } from '@/help/adr';
import type { HelpTopicDefinition } from '@/help/types';

/**
 * `Settings/ApiTokens/Index` — specs.md §18 (FR-API-01/02/03/05, US-API-01/02), plan §11.
 * `ApiTokenController`, `ApiTokenPolicy`, `ResolveTenantFromApiToken`, `EnsureTokenAbility`.
 */
const apiTokensTopic: HelpTopicDefinition = {
    id: 'api-tokens',
    adr: {
        id: 'ADR-008',
        title: 'Public API versioned in the path (/api/v1/...)',
        url: adrUrl('ADR-008', 'versionare-api-pe-cale'),
    },
};

export default apiTokensTopic;
