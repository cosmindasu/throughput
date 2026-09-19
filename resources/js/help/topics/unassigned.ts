import { adrUrl } from '@/help/adr';
import type { HelpTopic } from '@/help/types';

/**
 * `Unassigned/Index` — specs.md §6.4.1 (BR-TEN-05, FR-TEN-05), ADR-011.
 * `UnassignedController`, `DealList`/`OrderList` (filtrul `owner=unassigned`).
 */
const unassignedTopic: HelpTopic = {
    id: 'unassigned',
    title: 'Unassigned',
    whatIsThis:
        'Open deals and active orders left behind when a member was deactivated without reassigning them first. Nothing on this page was lost — it just needs a new owner.',
    whatCanYouDo: [
        'See every open deal and active order that belongs to a member who no longer has access, grouped by type.',
        'Pick a new owner and press "Reassign all" to move everything on this page to them in one action.',
        'Watch the number next to "Unassigned" in the main navigation — it only shows while this page has something in it.',
    ],
    rules: [
        'Only accounts, deals and orders that are still open show up here — a delivered order or a won/lost deal keeps its original owner, marked "(deactivated)", because rewriting closed history isn\'t the point.',
        'Accounts are not part of this view: a deactivated member\'s accounts stay assigned to them, visible with the "(deactivated)" mark, rather than appearing here — only their unfinished work does.',
        'Visible to Owner and Manager only — the same two roles that can deactivate a member in the first place.',
        'Reassigning here uses the same queued, chunked mechanism as any other bulk operation — you land on a status page and the "Unassigned" count drops once it finishes.',
    ],
    howItsBuilt: {
        summary:
            'This page is one more filter on the same `DealList`/`OrderList` queries every other list in the app already uses (`owner=unassigned`, plus the open/active status) — there is no separate "orphaned records" table to keep in sync. The navigation count runs the same two queries, without the pagination, once per request for Owner and Manager only.',
        adr: {
            id: 'ADR-011',
            title: 'Deactivating a member is never blocked by the records they own',
            url: adrUrl('ADR-011', 'dezactivare-membru-fara-blocare'),
        },
    },
};

export default unassignedTopic;
