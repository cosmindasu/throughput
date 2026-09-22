import i18n, { type AppLocale } from '@/lib/i18n';

/**
 * Încărcarea catalogului de ajutor — Valul 4 al Lotului I18N (ADR-022, FR-I18N-02).
 *
 * **Singurul catalog al proiectului încărcat LENEȘ, și de ce.** Celelalte 16 namespace-uri
 * intră static în bundle la bootstrap (`lib/i18n.ts`), pe ambele limbi: sunt etichete
 * scurte, de care fiecare ecran are nevoie la primul paint. Subiectele de ajutor sunt
 * opusul — ~107 KB de proză engleză (măsurat la externalizare: 38 de subiecte, 511 șiruri,
 * ~17.700 de cuvinte), plus încă ~15-20% în franceză, de care are nevoie DOAR cine apasă
 * „?". Măsurat pe build-ul de dinaintea acestui val: din cei 131,20 kB ai chunk-ului
 * `AppLayout` — încărcat pe FIECARE ecran autentificat — 110 kB erau chiar textul ăsta.
 * Importat static pe două limbi, chunk-ul ar fi ajuns la ~256 kB.
 *
 * Rezultatul măsurat după schimbare, nu estimat: `AppLayout` **131,20 kB → 26,57 kB**
 * (gzip 44,65 → 8,01 kB), iar catalogul iese în două chunk-uri proprii, câte unul pe
 * limbă, aduse doar la cerere. Pe tot primul răspuns autentificat (app + AppLayout,
 * i18next inclus în ambele numărători), 295,19 kB → 190,42 kB.
 *
 * **Nu contrazice ADR-022.** Interdicția de acolo e la adresa re-fetch-ului PER NAVIGARE
 * Inertia („încărcate la bootstrap-ul React, nu re-fetch-uite per navigare"); aici e o
 * singură încărcare per limbă și per sesiune, la prima deschidere a panoului, memorată în
 * `loaded` de mai jos. O navigare ulterioară nu mai atinge rețeaua.
 *
 * **Limba activă, nu ambele.** `LocaleToggle` comută limba client-side
 * (`i18n.changeLanguage` + `preserveState: true`, fără încărcare completă de pagină), deci
 * un utilizator care comută pe franceză cu panoul deja deschis are nevoie de al doilea
 * catalog fără reîncărcarea paginii. De aici mulțimea de limbi încărcate, nu un singur
 * boolean: a doua limbă se aduce la prima deschidere DUPĂ comutare, iar prima rămâne
 * încărcată dacă se comută înapoi.
 */
const loaded = new Set<AppLocale>();

export function helpCatalogIsLoaded(locale: AppLocale): boolean {
    return loaded.has(locale);
}

export async function loadHelpCatalog(locale: AppLocale): Promise<void> {
    if (loaded.has(locale)) {
        return;
    }

    // Două `import()` cu cale LITERALĂ, nu unul cu șablon: Vite trebuie să poată vedea
    // static ambele ținte ca să emită câte un chunk pentru fiecare. Un
    // `import(`@/locales/${locale}/help.json`)` ar funcționa, dar ar trage în bundle tot
    // ce se potrivește cu șablonul — adică toate cele 17 cataloage ale limbii, nu doar
    // `help.json`.
    const bundle = locale === 'fr'
        ? (await import('@/locales/fr/help.json')).default
        : (await import('@/locales/en/help.json')).default;

    // `deep = true`, `overwrite = true`: catalogul e sursa de adevăr pentru namespace-ul
    // lui, nu un supliment peste ceva deja prezent.
    i18n.addResourceBundle(locale, 'help', bundle, true, true);
    loaded.add(locale);
}
