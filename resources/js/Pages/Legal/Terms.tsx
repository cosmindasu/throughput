import { Head } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import GuestLayout from '@/Layouts/GuestLayout';

/**
 * GDPR-06 (audit 2026-09-23, §3) — Termeni de utilizare minimi, publici, pentru un demo de
 * portofoliu (nu un produs comercial real). Rută fixă `legal.terms` (`routes/web.php`),
 * accesibilă anonim ȘI autentificat — vezi docblock-ul complet pe `Privacy.tsx`, pagina
 * geamănă: aceeași structură, același `GuestLayout`, aceleași helpere de randare (duplicate
 * aici deliberat — motivul e explicat pe `Privacy.tsx`).
 */
export default function Terms() {
    const { t } = useTranslation('legal');

    return (
        <>
            <Head title={t('legal:terms.title')} />

            <div className="flex flex-col gap-6">
                <div>
                    <h1 className="text-xl font-semibold text-text">{t('legal:terms.heading')}</h1>
                    <p className="mt-1 text-xs text-text-3">{t('legal:terms.lastUpdated')}</p>
                </div>

                <p className="text-sm text-text-2">{t('legal:terms.intro')}</p>

                {SECTION_KEYS.map((key) => (
                    <LegalSection key={key} t={t} base={`legal:terms.sections.${key}`} />
                ))}
            </div>
        </>
    );
}

Terms.layout = (page: ReactNode) => <GuestLayout maxWidth="max-w-3xl">{page}</GuestLayout>;

/** Secțiunile paginii, în ordinea din `legal.json` — vezi `Privacy.tsx` pentru motiv. */
const SECTION_KEYS = ['nature', 'acceptableUse', 'sharedAccounts', 'noWarranty', 'intellectualProperty', 'governingLaw', 'contact', 'changes'] as const;

type TranslateFn = (key: string, options?: Record<string, unknown>) => unknown;

/** Identic cu `Privacy.tsx` — vezi docblock-ul de acolo. */
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

function translatedString(t: TranslateFn, key: string): string | null {
    const value = t(key);

    return typeof value === 'string' && value !== key ? value : null;
}

function translatedList(t: TranslateFn, key: string): string[] {
    const value = t(key, { returnObjects: true });

    return Array.isArray(value) ? value.filter((item): item is string => typeof item === 'string') : [];
}
