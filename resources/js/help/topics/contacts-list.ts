import type { HelpTopicDefinition } from '@/help/types';

/**
 * `Contacts/Index` — specs.md §8.1/8.3 (FR-CRM-02/03), same list mechanism as
 * Accounts (`ListQuery`/`ResourceList`).
 *
 * Reconciliat cu codul la 2026-09-14: `Pages/Contacts/Index.tsx` (căutare cu „Apply", „Sort by",
 * „Export CSV", „New contact" — și FĂRĂ `SavedViewPicker`: vizualizările salvate există doar pe
 * accounts/deals, `SavedViewResourceType`), `ContactList` (fără filtru implicit pe rol, căutare
 * pe câmpuri separate), `ContactPolicy` și `App\Support\Contacts\ContactErasure` — ștergerea
 * anonimizează în loc să șteargă fizic dacă există deals (inclusiv șterse) sau orders (§20.5);
 * contactele anonimizate dispar din listă și din export (`NotAnonymizedContactScope`).
 */
const contactsList: HelpTopicDefinition = {
    id: 'contacts-list',
};

export default contactsList;
