import accountDetail from '@/help/topics/account-detail';
import accountsList from '@/help/topics/accounts-list';
import activityLog from '@/help/topics/activity-log';
import apiTokens from '@/help/topics/api-tokens';
import billing from '@/help/topics/billing';
import bulkGroupOperation from '@/help/topics/bulk-group-operation';
import bulkOperation from '@/help/topics/bulk-operation';
import carrierSettings from '@/help/topics/carrier-settings';
import contactDetail from '@/help/topics/contact-detail';
import contactsList from '@/help/topics/contacts-list';
import dashboard from '@/help/topics/dashboard';
import dataExport from '@/help/topics/data-export';
import dealDetail from '@/help/topics/deal-detail';
import dealsKanban from '@/help/topics/deals-kanban';
import dealsList from '@/help/topics/deals-list';
import exportsTopic from '@/help/topics/exports';
import importDetail from '@/help/topics/import-detail';
import importUpload from '@/help/topics/import-upload';
import importsList from '@/help/topics/imports-list';
import invoiceDetail from '@/help/topics/invoice-detail';
import invoicesList from '@/help/topics/invoices-list';
import members from '@/help/topics/members';
import orderDetail from '@/help/topics/order-detail';
import ordersList from '@/help/topics/orders-list';
import pipeline from '@/help/topics/pipeline';
import preferences from '@/help/topics/preferences';
import productDetail from '@/help/topics/product-detail';
import productsList from '@/help/topics/products-list';
import reportDetail from '@/help/topics/report-detail';
import reportForm from '@/help/topics/report-form';
import reportsList from '@/help/topics/reports-list';
import sentEmails from '@/help/topics/sent-emails';
import settings from '@/help/topics/settings';
import stock from '@/help/topics/stock';
import stockHistory from '@/help/topics/stock-history';
import unassigned from '@/help/topics/unassigned';
import variantForm from '@/help/topics/variant-form';
import webhookHealth from '@/help/topics/webhook-health';
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
    'Settings/Members/Index': members,
    'Settings/SentEmails/Index': sentEmails,
    'Settings/Shipping/Index': carrierSettings,
    'Settings/Billing/Index': billing,
    // Valul 2 al Fazei 5. Niciuna dintre cele trei nu e în `NAV_ITEMS`, deci
    // `HelpTopicCoverageTest` nu le-ar fi cerut — panoul ar fi rămas gol fără ca
    // vreun test să semnaleze. Maparea e scrisă la integrare, nu de loturi.
    'Settings/ApiTokens/Index': apiTokens,
    'Settings/DataExport/Index': dataExport,
    'Settings/WebhookHealth/Index': webhookHealth,

    'Unassigned/Index': unassigned,

    'Exports/Show': exportsTopic,
    'Bulk/Show': bulkOperation,
    'Bulk/Groups/Show': bulkGroupOperation,

    'Products/Index': productsList,
    'Products/Show': productDetail,
    'Products/Create': productDetail,
    'Products/Edit': productDetail,
    'Variants/Create': variantForm,
    'Variants/Edit': variantForm,

    'Stock/Show': stock,
    'Stock/History': stockHistory,

    'Orders/Index': ordersList,
    'Orders/Show': orderDetail,
    'Orders/Create': orderDetail,
    'Orders/Edit': orderDetail,

    'Imports/Index': importsList,
    'Imports/Create': importUpload,
    'Imports/Show': importDetail,

    'Reports/Index': reportsList,
    'Reports/Show': reportDetail,
    'Reports/Create': reportForm,
    'Reports/Edit': reportForm,

    'Invoices/Index': invoicesList,
    'Invoices/Show': invoiceDetail,

    'Activity/Index': activityLog,
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
