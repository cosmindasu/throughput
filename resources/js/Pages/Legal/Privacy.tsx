import { Head } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import GuestLayout from '@/Layouts/GuestLayout';

/**
 * GDPR-06 (audit 2026-09-23, §3, Art. 13/14) — Politică de confidențialitate minimă,
 * publică, pentru un demo de portofoliu (nu un produs comercial real). Rută fixă
 * `legal.privacy` (`routes/web.php`), accesibilă anonim ȘI autentificat — de aici
 * `GuestLayout`, care nu depinde de context de workspace, la fel ca `Login.tsx`.
 *
 * Tot textul e static, din catalogul `legal` — nicio interpolare de props server-side:
 * conținutul legal nu variază pe utilizator sau pe workspace.
 *
 * Structura de titluri e PLATĂ și intenționată: un singur `<h1>` (titlul paginii), urmat
 * exclusiv de `<h2>` pentru fiecare secțiune numerotată — nicio secțiune nu are nevoie de
 * un al treilea nivel, deci nu se inventează unul „ca să fie".
 *
 * `LegalSection`/`translatedString`/`translatedList` sunt duplicate identic pe
 * `Terms.tsx`: un helper PARTAJAT ar trebui să stea sub `Components/`, în afara feliei
 * acestui pachet — două fișiere mici, nu o dependență nouă în afara scopului.
 */
export default function Privacy() {
    const { t } = useTranslation('legal');

    return (
        <>
            <Head title={t('legal:privacy.title')} />

            <div className="flex flex-col gap-6">
                <div>
                    <h1 className="text-xl font-semibold text-text">{t('legal:privacy.heading')}</h1>
                    <p className="mt-1 text-xs text-text-3">{t('legal:privacy.lastUpdated')}</p>
                </div>

                <p className="text-sm text-text-2">{t('legal:privacy.intro')}</p>

                {SECTION_KEYS.map((key) => (
                    <LegalSection key={key} t={t} base={`legal:privacy.sections.${key}`} />
                ))}
            </div>
        </>
    );
}

Privacy.layout = (page: ReactNode) => <GuestLayout maxWidth="max-w-3xl">{page}</GuestLayout>;

/**
 * Secțiunile paginii, în ordinea în care apar în `legal.json` — enumerate explicit (nu
 * derivate din `Object.keys` peste catalog) ca ordinea de randare să nu depindă de ordinea
 * de scriere a unui obiect JSON, care nu e garantată de `JSON.parse` la fel de strict ca
 * la un array.
 */
const SECTION_KEYS = [
    'whoWeAre',
    'twoRoles',
    'whatWeProcess',
    'noRealData',
    'legalBasis',
    'recipients',
    'hosting',
    'retention',
    'rights',
    'complaint',
    'changes',
] as const;

type TranslateFn = (key: string, options?: Record<string, unknown>) => unknown;

/**
 * O secțiune numerotată — `<h2>` + (opțional) un `intro`, o listă `<ul>` din `items`, un
 * bloc de paragrafe din `paragraphs`, și (doar la secțiunea „Recipients") un paragraf final
 * `transfers`. Fiecare bucată e opțională și randată doar dacă cheia respectivă există în
 * catalog — vezi `translatedString`/`translatedList` mai jos.
 */
function LegalSection({ t, base }: { t: TranslateFn; base: string }) {
    const intro = translatedString(t, `${base}.intro`);
    const paragraphs = translatedList(t, `${base}.paragraphs`);
    const items = translatedList(t, `${base}.items`);
    const transfers = translatedString(t, `${base}.transfers`);

    return (
        <section className="flex flex-col gap-2">
            <h2 className="text-sm font-semibold text-text">{translatedString(t, `${base}.heading`)}</h2>

            {intro && <p className="text-sm text-text-2">{intro}</p>}

            {paragraphs.map((paragraph, index) => (
                <p key={index} className="text-sm text-text-2">
                    {paragraph}
                </p>
            ))}

            {items.length > 0 && (
                <ul className="ml-4 flex list-disc flex-col gap-1.5 text-sm text-text-2">
                    {items.map((item, index) => (
                        <li key={index}>{item}</li>
                    ))}
                </ul>
            )}

            {transfers && <p className="text-sm text-text-2">{transfers}</p>}
        </section>
    );
}

/**
 * O cheie absentă din catalog întoarce, la i18next, cheia brută (un `string`) — nu
 * `undefined`. `intro`/`transfers` sunt opționale PER SECȚIUNE (nu toate au ambele), deci
 * randarea necondiționată ar arăta „legal:privacy.sections.whoWeAre.intro" pe ecran, pe
 * secțiunile care n-au acel câmp. Verificăm explicit că valoarea NU e cheia ei însăși.
 */
function translatedString(t: TranslateFn, key: string): string | null {
    const value = t(key);

    return typeof value === 'string' && value !== key ? value : null;
}

/**
 * `returnObjects: true` întoarce `unknown` în tipurile i18next fără `CustomTypeOptions`
 * declarat (proiectul nu-l declară — vezi `help/useHelpTopic.ts`, același tipar). O cheie
 * absentă întoarce cheia brută (un `string`, nu un array), deci lista rezultată e goală —
 * exact comportamentul dorit pentru secțiunile fără `items`/`paragraphs`.
 */
function translatedList(t: TranslateFn, key: string): string[] {
    const value = t(key, { returnObjects: true });

    return Array.isArray(value) ? value.filter((item): item is string => typeof item === 'string') : [];
}
