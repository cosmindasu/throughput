import type { HelpTopicDefinition } from '@/help/types';

/**
 * `Contacts/Show`, `Contacts/Create`, `Contacts/Edit` — specs.md §8.1/8.2/8.3
 * (FR-CRM-02, US-CRM-01, BR-CRM-02).
 *
 * Reconciliat cu codul la 2026-09-14: `Pages/Contacts/Show.tsx` („Edit", „Delete", „Marketing",
 * „Deals as primary contact"), `Components/Contacts/ContactForm.tsx` (etichetele câmpurilor,
 * `AccountCombobox`, „Create contact"/„Save changes"), `StoreContactRequest`/
 * `UpdateContactRequest` (primary cere cont), `PrimaryContactAssignment`, `ContactPolicy` și
 * `App\Support\Contacts\ContactErasure` — ștergerea anonimizează în loc să șteargă fizic dacă
 * există deals (inclusiv șterse) sau orders (§20.5), exact cum spune dialogul de confirmare.
 */
const contactDetail: HelpTopicDefinition = {
    id: 'contact-detail',
};

export default contactDetail;
