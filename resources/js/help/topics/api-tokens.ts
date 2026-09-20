import { adrUrl } from '@/help/adr';
import type { HelpTopic } from '@/help/types';

/**
 * `Settings/ApiTokens/Index` — specs.md §18 (FR-API-01/02/03/05, US-API-01/02), plan §11.
 * `ApiTokenController`, `ApiTokenPolicy`, `ResolveTenantFromApiToken`, `EnsureTokenAbility`.
 */
const apiTokensTopic: HelpTopic = {
    id: 'api-tokens',
    title: 'API tokens',
    whatIsThis:
        'The keys that let an outside system — an ERP, a spreadsheet script, a partner integration — read and write this workspace through the public API, without a browser session and without anyone sharing a password.',
    whatCanYouDo: [
        'Create a token with a name that says what it is for, and tick only the scopes it actually needs.',
        'Copy the token value the one time it is shown, straight after "Create token".',
        'See when each token was last used, so a token nobody calls any more is easy to spot.',
        'Press "Revoke" on a token to stop it working immediately, without touching the other tokens.',
        'Read the full endpoint reference at /api/documentation, which is generated from the same contract the API is built against.',
    ],
    rules: [
        'The token value is shown exactly once, at creation. It is never stored in readable form, so nobody — including an administrator with database access — can recover it later. Lose it and you issue a new one.',
        'A token belongs to one workspace, and the API works out which workspace from the token itself: API addresses carry no workspace in them. Pointing a token at another workspace is not something the URL can express.',
        'Scopes are read and write separately, per area — except accounts, which the API only ever reads. A token with only "Read orders" that tries to create an order is refused with a message naming the scope it is missing, rather than a vague failure.',
        'A token can never do more than the person who issued it. A Viewer who somehow creates a write-scoped token still gets refused by the ordinary permission rules — the scope narrows, it never widens.',
        'Only an Owner or a Manager sees this screen or can create and revoke tokens.',
        'Revoking keeps the row, so the history of who issued what stays readable, but the key stops working on the very next request.',
        'Creating an order, an invoice or a stock movement through the API requires an "Idempotency-Key" header. Sending the same key twice returns the first result again instead of creating a second record — a network timeout and a retry cannot duplicate anything.',
        'Each token is limited to 300 requests per minute. Past that, the API answers with a "Retry-After" header saying how long to wait.',
        'If the member who issued a token is deactivated, every token they issued stops working too — access follows the person, not the key.',
    ],
    howItsBuilt: {
        summary:
            "Tokens sit on top of Laravel Sanctum rather than a full OAuth2 server: this application is a first-party SPA plus simple machine access, and nothing in it needs third-party consent flows. Each token carries its workspace on the authentication record itself, so the workspace is resolved server-side from the verified token before a single business query runs — the API path deliberately has no workspace segment, which is what makes \"read another tenant's order by guessing its id\" structurally impossible rather than merely forbidden. Identifiers are ULIDs, not counters, so they cannot be walked; and a request for a record belonging to another workspace answers 404, not 403, because 403 would confirm that the record exists. Idempotency is enforced by storing the key together with a fingerprint of the request body for 24 hours, inside the same database transaction as the effect itself: if the request fails the claim rolls back with it, and if it succeeds the stored response and the record it describes are committed together.",
        adr: {
            id: 'ADR-008',
            title: 'Public API versioning lives in the path',
            url: adrUrl('ADR-008', 'versionare-api-pe-cale'),
        },
    },
};

export default apiTokensTopic;
