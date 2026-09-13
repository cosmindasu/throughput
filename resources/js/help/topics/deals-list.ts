import type { HelpTopic } from '@/help/types';

/**
 * `Deals/Index` — specs.md §9, §15 (vizualizări salvate aplicate și pe Deals).
 * Vedere alternativă la `Deals/Kanban` pentru cine preferă un tabel filtrabil/
 * sortabil în locul board-ului (ex: export, sortare pe valoare).
 *
 * Presupuneri de buton semnalate în raport: „Board view" (link către Kanban),
 * „Export CSV" — confirmate la construirea paginii (pachet paralel).
 */
const dealsList: HelpTopic = {
    id: 'deals-list',
    title: 'Deals — list',
    whatIsThis:
        'The same deals as the Kanban board, as a sortable, filterable table — useful when you want to sort by value or close date rather than scan columns.',
    whatCanYouDo: [
        'Sort and filter by stage, owner, value or expected close date.',
        'Save the current filters as a view, private or shared with the team.',
        'Export the filtered list to CSV.',
        'Switch to "Board view" for the Kanban layout.',
    ],
    rules: [
        'Agents see their own deals by default, same as on the board and on Accounts.',
        'Export is available to Viewers too — it reads what is already on screen.',
        'Sorting and filtering here never changes a deal\'s stage — moving stage only happens from the board or from the deal\'s own page.',
    ],
    howItsBuilt: {
        summary:
            'Same cursor-paginated `ListQuery` foundation as Accounts and Contacts — see the Kanban topic\'s "How it\'s built" for why deal-stage history itself is stored the way it is (append-only, not a mutable column).',
    },
};

export default dealsList;
