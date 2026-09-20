import { adrUrl } from '@/help/adr';
import type { HelpTopic } from '@/help/types';

/**
 * `Settings/Shipping/Index` — specs.md §11.5/§7.4 (FR-ORD-06, BR-ORD-03), ADR-010.
 * `CarrierSettingController`, `ActivateCarrierAction`, `TenantCarrierSettingPolicy`.
 */
const carrierSettingsTopic: HelpTopic = {
    id: 'carrier-settings',
    title: 'Carrier settings',
    whatIsThis:
        'Which shipping carrier this workspace uses to generate labels, and the credentials it uses to talk to it. Only one carrier is active at a time.',
    whatCanYouDo: [
        'See both available carriers — Demo (no external calls) and Shippo (a real sandbox integration) — and which one is active right now.',
        'Add or rotate the Shippo API key for this workspace.',
        'Press "Activate" (or "Save & activate") on a carrier to make it the one used for every new shipping label from now on.',
    ],
    rules: [
        'Exactly one carrier is active per workspace, always — activating one automatically deactivates whichever was active before, in the same instant, so a shipment is never created without a clear owner for the label.',
        'Shippo cannot be activated without an API key on file; the form asks for one before it lets you save.',
        'Leaving the API key field blank on an already-configured carrier keeps the existing key — the field never shows a real key back, only whether one is on file and the last few characters, so re-saving never accidentally erases it.',
        'The key itself never appears anywhere once saved: not in this screen, not in the activity log, not in any exported file. Only "configured" and its last few characters are ever shown again.',
        'Only an Owner sees this screen at all — not Manager, Agent or Viewer (the one row in the permissions matrix where Manager has no access at all, not even read-only).',
        'This deployment only ever uses Shippo sandbox/test keys, never a live key — a real shipment is never actually created or billed.',
    ],
    howItsBuilt: {
        summary:
            'Both carriers implement the same `ShippingCarrier` interface (`createLabel`, `void`, `trackingUrl`), run through the identical contract test suite, and are selected per tenant from a `tenant_carrier_settings` row rather than a global setting — so two workspaces can use the same carrier with entirely separate credentials. The "exactly one active" rule is enforced in the database transaction that activates a carrier, not just in the form: it locks the tenant\'s own row with `FOR NO KEY UPDATE` (serializing two concurrent activations without blocking unrelated writes elsewhere in the workspace) before flipping the old active row off and the new one on. Credentials are stored with Laravel\'s `encrypted:array` cast — unreadable at rest even with direct database access — and this screen only ever receives a boolean and a truncated preview back from the server, never the decrypted value.',
        adr: {
            id: 'ADR-010',
            title: 'Shipping carriers are pluggable and configured per tenant',
            url: adrUrl('ADR-010', 'doi-furnizori-curierat-configurabili'),
        },
    },
};

export default carrierSettingsTopic;
