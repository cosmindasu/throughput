import type { HelpTopicDefinition } from '@/help/types';

/**
 * `Deals/Index` — specs.md §9, §15 (vizualizări salvate aplicate și pe Deals).
 * Vedere alternativă la `Deals/Kanban` pentru cine preferă un tabel filtrabil/
 * sortabil în locul board-ului.
 *
 * Reconciliat cu codul la 2026-09-13: `Pages/Deals/Index.tsx` (căutare, „Status", „My deals"/
 * „All deals", antete sortabile, `SavedViewPicker`, `ViewSwitcher` „List"/„Board" — fără export
 * CSV și fără buton de creare), `DealList` (implicit `owner=me` pentru Agent, sortarea pe
 * `value_sort` și `expected_close_date_sort`). Formularul de deal cu câmp „Account" căutabil e schimbarea paralelă descrisă
 * în `deal-detail.ts`.
 */
const dealsList: HelpTopicDefinition = {
    id: 'deals-list',
};

export default dealsList;
