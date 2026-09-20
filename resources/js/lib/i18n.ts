import i18n from 'i18next';
import { initReactI18next } from 'react-i18next';
import commonEn from '@/locales/en/common.json';
import settingsEn from '@/locales/en/settings.json';
import commonFr from '@/locales/fr/common.json';
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
 * Bootstrap i18next — Val 1 din „Lot I18N" (plan-implementare.md, între Fazele 5 și 6,
 * ADR-022). Motorul complet, cataloage-schelet: doar comutatorul, câteva etichete din
 * Settings → Preferences și un caz de pluralizare (`settings:language.available`).
 * Extragerea celor ~385 de etichete ale suprafeței rămâne Val 3 — namespace-urile de mai
 * jos (`common`, `settings`) sunt gândite să se extindă unul per modul din
 * `resources/js/Pages/` (accounts, deals, orders, ...), nu să se reorganizeze atunci.
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
    ns: ['common', 'settings'],
    resources: {
        en: { common: commonEn, settings: settingsEn },
        fr: { common: commonFr, settings: settingsFr },
    },
    interpolation: {
        // React scapă deja textul la randare — o a doua scăpare (implicitul i18next
        // pentru contexte non-React) ar strica entitățile din cataloage (apostroful din
        // traducerile franceze, de ex. „l'affichage").
        escapeValue: false,
    },
    // Val 1 e schelet, nu suprafața completă — o cheie lipsă azi nu trebuie să crape
    // ecranul, doar să cadă vizibil pe cheia brută (comportamentul implicit al
    // bibliotecii). Acoperirea reală, blocantă, vine din `php artisan i18n:coverage`
    // (App\Console\Commands\I18nCoverage, rulat în CI), nu dintr-un fallback tăcut aici.
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
