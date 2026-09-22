import { useTranslation } from 'react-i18next';
import { helpTopicDefinitionForComponent } from '@/help';
import type { HelpTopic } from '@/help/types';

/**
 * Compune un `HelpTopic` complet din definiția independentă de limbă (`id` + blocul `adr`)
 * și textul din catalogul limbii active — Valul 4 al Lotului I18N (ADR-022).
 *
 * Apelantul garantează că `loadHelpCatalog(locale)` s-a terminat înainte (vezi
 * `help/catalog.ts`): namespace-ul `help` e declarat în `ns`, dar resursele lui ajung în
 * i18next abia la `addResourceBundle`. Fără garanția asta, `t()` ar întoarce cheia brută,
 * iar panoul ar arăta „topics.import-upload.title" în loc de titlu — de aceea `HelpPanel`
 * ține o stare proprie de „catalog gata" și nu randează conținutul până atunci.
 *
 * `useSuspense: false`: proiectul n-are un `<Suspense>` în jurul layout-ului, iar un
 * namespace care ajunge prin `addResourceBundle` (nu printr-un backend) n-are oricum ce
 * suspenda — ar fi o graniță de suspendare care nu se rezolvă niciodată singură.
 */
export function useHelpTopic(component: string): HelpTopic | null {
    const { t } = useTranslation('help', { useSuspense: false });
    const definition = helpTopicDefinitionForComponent(component);

    if (!definition) {
        return null;
    }

    const base = `topics.${definition.id}`;

    return {
        id: definition.id,
        title: asString(t(`${base}.title`)),
        whatIsThis: asString(t(`${base}.whatIsThis`)),
        whatCanYouDo: asStrings(t(`${base}.whatCanYouDo`, { returnObjects: true })),
        rules: asStrings(t(`${base}.rules`, { returnObjects: true })),
        howItsBuilt: {
            summary: asString(t(`${base}.howItsBuilt`)),
            adr: definition.adr,
        },
    };
}

function asString(value: unknown): string {
    return typeof value === 'string' ? value : '';
}

/**
 * `returnObjects: true` întoarce `unknown` în tipurile i18next fără `CustomTypeOptions`
 * declarat (proiectul nu-l declară). Îngustarea se face aici, o dată, nu cu un `as string[]`
 * la fiecare apel: o cheie lipsă ar întoarce cheia brută (un `string`), nu un array, iar o
 * listă goală e mai onestă în panou decât `"topics.x.rules".map is not a function`.
 */
function asStrings(value: unknown): string[] {
    return Array.isArray(value) ? value.filter((item): item is string => typeof item === 'string') : [];
}
