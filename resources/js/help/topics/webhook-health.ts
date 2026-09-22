import { adrUrl } from '@/help/adr';
import type { HelpTopicDefinition } from '@/help/types';

/**
 * `Settings/WebhookHealth/Index` — specs.md §25.2 („ecran «Webhook health», intern") și
 * criteriul de acceptanță din §12.3 („`error_message` populat, vizibil într-un ecran de
 * operare").
 *
 * Scris odată cu ecranul (Faza 5, valul 2). Linia de hartă din `resources/js/help/index.ts`
 * e un fișier de integrare, neatins de acest lot — vezi raportul.
 */
const webhookHealth: HelpTopicDefinition = {
    id: 'webhook-health',
    adr: {
        id: 'ADR-013',
        title: 'External calls leave the HTTP request and move to queues',
        url: adrUrl('ADR-013', 'apeluri-externe-in-cozi'),
    },
};

export default webhookHealth;
