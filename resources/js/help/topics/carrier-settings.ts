import { adrUrl } from '@/help/adr';
import type { HelpTopicDefinition } from '@/help/types';

/**
 * `Settings/Shipping/Index` — specs.md §11.5/§7.4 (FR-ORD-06, BR-ORD-03), ADR-010.
 * `CarrierSettingController`, `ActivateCarrierAction`, `TenantCarrierSettingPolicy`.
 */
const carrierSettingsTopic: HelpTopicDefinition = {
    id: 'carrier-settings',
    adr: {
        id: 'ADR-010',
        title: 'Two shipping carriers, selectable per tenant, plus a demo carrier',
        url: adrUrl('ADR-010', 'doi-furnizori-curierat-configurabili'),
    },
};

export default carrierSettingsTopic;
