import { router, usePage } from '@inertiajs/react';
import { useId } from 'react';
import { useTranslation } from 'react-i18next';
import i18n, { applyDocumentLocale, persistLocaleCookie, type AppLocale } from '@/lib/i18n';

// Codurile de limbă NU trec prin `t()`: „EN"/„FR" sunt nume auto-referențiale (convenție
// UX obișnuită pentru un comutator de limbă — un vorbitor de franceză recunoaște „FR"
// indiferent de limba curentă a interfeței), nu conținut care s-ar traduce cu ecranul.
const OPTIONS: Array<{ value: AppLocale; label: string }> = [
    { value: 'en', label: 'EN' },
    { value: 'fr', label: 'FR' },
];

/**
 * ADR-022, specs.md §15.8 FR-I18N-01 — comutator de limbă cu DOUĂ stări, pe modelul
 * EXACT al lui `ThemeToggle` (Components/ThemeToggle.tsx): `<fieldset>` +
 * `<input type="radio">` REALE (navigare cu săgețile/Tab/Space și inel de focus gratis
 * de la browser, stare curentă anunțată nativ de screen reader, fără ARIA de mână).
 *
 * Spre deosebire de temă, nu există o a treia stare de tip „System" (vezi
 * `resources/js/types/inertia.d.ts`) — alegerea ȘI rezoluția coincid mereu — și,
 * spre deosebire de `ThemeToggle`, montat dintr-un SINGUR loc
 * (`Pages/Settings/Preferences.tsx`): planul (plan-implementare.md, „Lot I18N" Val 1)
 * nu cere o a doua instanță în bara de sus.
 */
export default function LocaleToggle() {
    const { t } = useTranslation('common');
    const { auth } = usePage().props;
    const groupName = useId();
    const choice: AppLocale = auth.user?.locale ?? 'en';

    const select = (next: AppLocale) => {
        if (next === choice) {
            return;
        }

        // Feedback instant, simetric cu `applyResolvedTheme` din ThemeToggle: schimbă
        // limba activă a instanței i18next ȘI `<html lang>` ÎNAINTE de round-trip-ul de
        // mai jos, ca un screen reader să anunțe corect starea nouă fără să aștepte
        // răspunsul serverului. Cataloagele sunt deja în bundle (import static în
        // `lib/i18n.ts`), deci `changeLanguage` nu declanșează niciun fetch.
        void i18n.changeLanguage(next);
        applyDocumentLocale(next);

        // Persistă REZOLUȚIA în cookie-ul citit server-side (`LocalePreference`), ca
        // următoarea încărcare completă să nu arate o limbă, apoi alta — oglinda lui
        // `persistResolvedThemeCookie`.
        persistLocaleCookie(next);

        // Persistă ALEGEREA pe `users.locale` (`PATCH /preferences/locale`,
        // `LocaleController::update`, `back()`). Calea e literală, nu `route()`: la fel
        // ca `ThemeToggle`, proiectul nu folosește Ziggy pe rutele de preferințe.
        router.patch('/preferences/locale', { locale: next }, { preserveScroll: true, preserveState: true });
    };

    return (
        <fieldset className="flex items-center gap-0.5 rounded-md border border-control p-0.5">
            <legend className="sr-only">{t('common:localeToggle.legend')}</legend>
            {OPTIONS.map((option) => {
                const active = choice === option.value;

                return (
                    <label
                        key={option.value}
                        className={`cursor-pointer rounded px-2 py-1 text-xs font-medium transition-colors has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-offset-2 has-[:focus-visible]:outline-focus ${
                            active ? 'bg-accent-fill text-accent-on' : 'text-text-2 hover:text-text'
                        }`}
                    >
                        <input
                            type="radio"
                            name={`locale-${groupName}`}
                            value={option.value}
                            checked={active}
                            onChange={() => select(option.value)}
                            className="sr-only"
                        />
                        {option.label}
                    </label>
                );
            })}
        </fieldset>
    );
}
