import { useTranslation } from 'react-i18next';
import type { AppLocale } from '@/lib/i18n';

/**
 * Limba activă a interfeței, pentru formatarea locale-aware (`FR-I18N-03`) — Val 3 din
 * „Lot I18N" (ADR-022).
 *
 * **De ce un hook și nu o citire directă din singleton-ul `i18n`.** `lib/format.ts` și
 * `lib/money.ts` ar putea citi singure `i18n.language` la momentul apelului, iar call
 * site-urile n-ar mai primi niciun parametru. Ar fi fost greșit, și nu subtil:
 * `Components/LocaleToggle.tsx` comută limba prin `i18n.changeLanguage()` + un
 * `router.patch(..., { preserveState: true })`. `preserveState` ține componenta de pagină
 * MONTATĂ (`.ai/rules/frontend.md` — o navigare Inertia obișnuită ar remonta-o cu o `key`
 * nouă, asta NU o face). Deci re-randarea după comutare vine exclusiv din abonamentul
 * react-i18next, iar o componentă care doar formatează date, fără niciun apel `t()`, n-ar
 * fi avut la ce să se aboneze: ar fi rămas cu textul în limba veche până la următoarea
 * navigare reală. Bug vizibil, dar doar pe ecranele fără nicio etichetă tradusă — adică
 * exact cele pe care nimeni nu le-ar fi verificat după comutare.
 *
 * `useTranslation()` fără namespace încarcă implicitul (`common`) și, mai important,
 * abonează componenta la `languageChanged`. Valoarea se îngustează la `AppLocale`:
 * `i18n.language` e `string` în tipurile bibliotecii, iar formatoarele cer una dintre
 * cele două limbi cunoscute. Fallback pe `en`, simetric cu `lib/i18n.ts`.
 */
export function useLocale(): AppLocale {
    const { i18n } = useTranslation();

    return i18n.language === 'fr' ? 'fr' : 'en';
}
