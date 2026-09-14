import accountDetail from '@/help/topics/account-detail';
import accountsList from '@/help/topics/accounts-list';
import contactDetail from '@/help/topics/contact-detail';
import contactsList from '@/help/topics/contacts-list';
import dashboard from '@/help/topics/dashboard';
import dealDetail from '@/help/topics/deal-detail';
import dealsKanban from '@/help/topics/deals-kanban';
import dealsList from '@/help/topics/deals-list';
import exportsTopic from '@/help/topics/exports';
import pipeline from '@/help/topics/pipeline';
import preferences from '@/help/topics/preferences';
import productDetail from '@/help/topics/product-detail';
import productsList from '@/help/topics/products-list';
import settings from '@/help/topics/settings';
import stock from '@/help/topics/stock';
import stockHistory from '@/help/topics/stock-history';
import variantForm from '@/help/topics/variant-form';
import type { HelpTopic } from '@/help/types';

/**
 * Harta COMPONENTĂ INERTIA → SUBIECT (FR-HELP-03/04). Cheile sunt exact numele
 * returnate de `Inertia::render('Nume/Componentă', ...)`, adică exact ce
 * întoarce `usePage().component` în React — nu nume de rută, nu segmente de URL.
 *
 * Mai multe componente pot împărți un subiect (ex. `Accounts/Create` și
 * `Accounts/Edit` — un formular aproape identic nu merită două texte separate).
 *
 * `tests/Feature/Help/HelpTopicCoverageTest.php` parsează cheile de aici cu
 * regex (Pest rulează în PHP, nu poate importa acest modul TS direct) — dacă
 * schimbi formatul obiectului literal de mai jos, verifică și acel test.
 */
export const HELP_TOPICS_BY_COMPONENT: Record<string, HelpTopic> = {
    // Chei mereu între ghilimele simple, deși `Dashboard` ar fi un identificator
    // JS valid și fără ele — uniform, ca regexul din testul de acoperire
    // (HelpTopicCoverageTest, Pest rulează în PHP, nu importă acest modul) să
    // aibă un singur format de parsat, nu două.
    'Dashboard': dashboard,
    'Accounts/Index': accountsList,
    'Accounts/Show': accountDetail,
    'Accounts/Create': accountDetail,
    'Accounts/Edit': accountDetail,

    'Contacts/Index': contactsList,
    'Contacts/Show': contactDetail,
    'Contacts/Create': contactDetail,
    'Contacts/Edit': contactDetail,

    'Deals/Kanban': dealsKanban,
    'Deals/Index': dealsList,
    'Deals/Show': dealDetail,
    'Deals/Create': dealDetail,
    'Deals/Edit': dealDetail,

    'Pipeline/Index': pipeline,

    'Settings/Index': settings,
    'Settings/Preferences': preferences,

    'Exports/Show': exportsTopic,

    'Products/Index': productsList,
    'Products/Show': productDetail,
    'Products/Create': productDetail,
    'Products/Edit': productDetail,
    'Variants/Create': variantForm,
    'Variants/Edit': variantForm,

    'Stock/Show': stock,
    'Stock/History': stockHistory,
};

/**
 * `null` e o valoare validă și DELIBERATĂ, nu un caz de eroare: o pagină fără
 * subiect nu trebuie să arate un buton „?" gol (FR-HELP-01 impune conținut
 * complet pe fiecare subiect existent) — `HelpPanel` citește acest rezultat și,
 * dacă e `null`, pur și simplu NU randează butonul. Testul de acoperire
 * (FR-HELP-04) face situația imposibilă pe orice rută din navigația principală
 * plus Dashboard; pentru restul (module încă neconstruite), absența butonului
 * e comportamentul corect, nu unul degradat.
 */
export function helpTopicForComponent(component: string): HelpTopic | null {
    return HELP_TOPICS_BY_COMPONENT[component] ?? null;
}
