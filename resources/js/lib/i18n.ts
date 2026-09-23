import i18n from 'i18next';
import { initReactI18next } from 'react-i18next';
import accountsEn from '@/locales/en/accounts.json';
import activityEn from '@/locales/en/activity.json';
import authEn from '@/locales/en/auth.json';
import bulkEn from '@/locales/en/bulk.json';
import commonEn from '@/locales/en/common.json';
import contactsEn from '@/locales/en/contacts.json';
import dashboardEn from '@/locales/en/dashboard.json';
import dealsEn from '@/locales/en/deals.json';
import importsEn from '@/locales/en/imports.json';
import invoicesEn from '@/locales/en/invoices.json';
import legalEn from '@/locales/en/legal.json';
import ordersEn from '@/locales/en/orders.json';
import productsEn from '@/locales/en/products.json';
import reportsEn from '@/locales/en/reports.json';
import rolesEn from '@/locales/en/roles.json';
import searchEn from '@/locales/en/search.json';
import settingsEn from '@/locales/en/settings.json';
import accountsFr from '@/locales/fr/accounts.json';
import activityFr from '@/locales/fr/activity.json';
import authFr from '@/locales/fr/auth.json';
import bulkFr from '@/locales/fr/bulk.json';
import commonFr from '@/locales/fr/common.json';
import contactsFr from '@/locales/fr/contacts.json';
import dashboardFr from '@/locales/fr/dashboard.json';
import dealsFr from '@/locales/fr/deals.json';
import importsFr from '@/locales/fr/imports.json';
import invoicesFr from '@/locales/fr/invoices.json';
import legalFr from '@/locales/fr/legal.json';
import ordersFr from '@/locales/fr/orders.json';
import productsFr from '@/locales/fr/products.json';
import reportsFr from '@/locales/fr/reports.json';
import rolesFr from '@/locales/fr/roles.json';
import searchFr from '@/locales/fr/search.json';
import settingsFr from '@/locales/fr/settings.json';

export type AppLocale = 'en' | 'fr';

const SUPPORTED_LOCALES: readonly AppLocale[] = ['en', 'fr'];

function isAppLocale(value: string): value is AppLocale {
    return (SUPPORTED_LOCALES as readonly string[]).includes(value);
}

/**
 * Limba INIȚIALĂ vine din `<html lang>`, nu dintr-un prop Inertia citit asincron.
 * `App\Support\LocalePreference` (ADR-022) îl randează server-side, în
 * `resources/views/app.blade.php`, ÎNAINTEA acestui script — la momentul în care acest
 * modul rulează, atributul e deja pe pagină, citit sincron din DOM. Evită exact
 * fereastra pe care randarea server-side a lui `lang` o evită deja pentru screen reader
 * la temă (FR-PREF-03): un `useEffect` care ar corecta limba DUPĂ hidratare ar produce
 * un prim paint în engleză urmat de un re-render în franceză.
 *
 * Fallback pe `en` dacă atributul lipsește sau poartă o valoare neașteptată (SSR
 * dezactivat, test, sau o pagină randată în afara `app.blade.php`).
 */
const initialLocale: AppLocale = isAppLocale(document.documentElement.lang) ? document.documentElement.lang : 'en';

/**
 * **Un namespace per modul de ecran**, exact extinderea anticipată la Valul 1: cataloagele
 * `common`/`settings` de atunci erau schelet, iar comentariul de acolo cerea ca Valul 3 să
 * le EXTINDĂ pe module (`accounts`, `deals`, `orders`, …), nu să le reorganizeze. Granița
 * urmărește ecranul, nu componenta: un `Pages/Deals/*` și componentele lui din
 * `Components/Deals/*` împart `deals`, fiindcă se traduc împreună și se citesc împreună.
 *
 * `common` rămâne pentru shell-ul propriu-zis — layout, paginare, primitive de formular,
 * butoane — adică ce apare pe fiecare ecran indiferent de modul. `bulk` și `search` sunt
 * separate deși sunt tot componente partajate: sunt funcționalități de sine stătătoare, cu
 * vocabular propriu (și cu pluralizarea cea mai densă din aplicație).
 *
 * Împărțirea are și un motiv operațional: extragerea celor ~385 de etichete s-a făcut pe
 * loturi paralele, iar un namespace per lot înseamnă că două loturi nu scriu niciodată în
 * același fișier de catalog.
 */
const NAMESPACES = [
    'common',
    'settings',
    'accounts',
    'contacts',
    'activity',
    'deals',
    'orders',
    'products',
    'invoices',
    'reports',
    'imports',
    'bulk',
    'search',
    'auth',
    'dashboard',
    'roles',
    // GDPR-06 (audit 2026-09-23) — cataloagele celor două pagini publice minime
    // (`Pages/Legal/Privacy.tsx`/`Terms.tsx`), fără legătură cu vreun modul de business —
    // de aici un namespace propriu, nu o extindere a lui `common`.
    'legal',
    // Declarat aici, dar ABSENT din `resources` de mai jos — singurul namespace al
    // proiectului fără import static. Resursele lui ajung prin `addResourceBundle`, la
    // prima deschidere a panoului de ajutor, pe limba activă; vezi `help/catalog.ts`
    // pentru cifra măsurată care motivează excepția (110 KB din 131 KB ai chunk-ului
    // `AppLayout`, pe fiecare ecran, pentru un text pe care îl citește doar cine apasă
    // „?"). Declararea aici nu e decor: fără ea, `useTranslation('help')` ar cere un
    // namespace necunoscut instanței.
    'help',
] as const;

/**
 * Bootstrap i18next — „Lot I18N" (plan-implementare.md, între Fazele 5 și 6, ADR-022).
 *
 * Cataloagele sunt import-uri STATICE, incluse în bundle la build — NU
 * `i18next-http-backend` cu fetch la runtime. Un catalog per navigare Inertia ar
 * însemna un round-trip suplimentar la fiecare tranziție de pagină, exact ce
 * plan-implementare.md interzice explicit („încărcate din propul `locale` la bootstrap-ul
 * React, nu re-fetch-uite per navigare Inertia").
 *
 * Fără `.use(Backend)`/`.use(LanguageDetector)`: sursa limbii e `<html lang>` de mai sus,
 * nu un detector care ar putea contrazice FR-I18N-01 (interzice explicit `Accept-Language`
 * ca sursă).
 */
void i18n.use(initReactI18next).init({
    lng: initialLocale,
    fallbackLng: 'en',
    supportedLngs: SUPPORTED_LOCALES,
    defaultNS: 'common',
    ns: NAMESPACES,
    resources: {
        en: {
            common: commonEn,
            settings: settingsEn,
            accounts: accountsEn,
            contacts: contactsEn,
            activity: activityEn,
            deals: dealsEn,
            orders: ordersEn,
            products: productsEn,
            invoices: invoicesEn,
            reports: reportsEn,
            imports: importsEn,
            bulk: bulkEn,
            search: searchEn,
            auth: authEn,
            dashboard: dashboardEn,
            roles: rolesEn,
            legal: legalEn,
        },
        fr: {
            common: commonFr,
            settings: settingsFr,
            accounts: accountsFr,
            contacts: contactsFr,
            activity: activityFr,
            deals: dealsFr,
            orders: ordersFr,
            products: productsFr,
            invoices: invoicesFr,
            reports: reportsFr,
            imports: importsFr,
            bulk: bulkFr,
            search: searchFr,
            auth: authFr,
            dashboard: dashboardFr,
            roles: rolesFr,
            legal: legalFr,
        },
    },
    interpolation: {
        // React scapă deja textul la randare — o a doua scăpare (implicitul i18next
        // pentru contexte non-React) ar strica entitățile din cataloage (apostroful din
        // traducerile franceze, de ex. „l'affichage").
        escapeValue: false,
    },
    // O cheie lipsă nu trebuie să crape ecranul, doar să cadă vizibil pe cheia brută
    // (comportamentul implicit al bibliotecii). Acoperirea reală, blocantă, vine din
    // `php artisan i18n:coverage` (App\Console\Commands\I18nCoverage, rulat în CI),
    // nu dintr-un fallback tăcut aici.
    returnEmptyString: false,
});

/**
 * Necriptat, citit direct de `resources/views/app.blade.php`
 * (`bootstrap/app.php` exceptează `locale`, ca și `theme`, de la `EncryptCookies`) —
 * oglinda lui `persistResolvedThemeCookie` din `hooks/useThemeSync.ts`, pe modelul
 * exact al `LocaleController::update` (server), ca următoarea randare completă să nu
 * arate o limbă, apoi alta.
 */
const LOCALE_COOKIE = 'locale';
const COOKIE_MAX_AGE = 60 * 60 * 24 * 365; // un an

export function persistLocaleCookie(locale: AppLocale): void {
    const secure = typeof location !== 'undefined' && location.protocol === 'https:' ? '; secure' : '';

    document.cookie = `${LOCALE_COOKIE}=${locale}; path=/; max-age=${COOKIE_MAX_AGE}; samesite=lax${secure}`;
}

/**
 * Definită AICI, în afara oricărui component/hook, deliberat — `eslint-plugin-react-hooks`
 * (regula `react-hooks/immutability`) interzice mutarea directă a unei valori globale
 * (`document.documentElement...`) din interiorul corpului unui component, chiar și
 * dintr-un handler de eveniment. `hooks/useThemeSync.ts` rezolvă identic pentru temă
 * (`applyResolvedTheme`) — același tipar, aplicat acum și pentru `lang`.
 */
export function applyDocumentLocale(locale: AppLocale): void {
    document.documentElement.lang = locale;
}

export default i18n;
