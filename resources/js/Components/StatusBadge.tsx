import type { ReactNode } from 'react';
import { TONE, type Tone } from '@/lib/tone';

/**
 * Alias istoric. Insigna a fost primul consumator al tentelor, deci numele a rămas în
 * ~40 de situri de apel; tipul însuși e acum `Tone` (`lib/tone.ts`), unde stă și harta.
 */
export type BadgeTone = Tone;

/**
 * Perechea „tentă de fundal + culoare de text", DERIVATĂ din `TONE` — nu o a doua copie.
 * Versiunea anterioară repeta aceleași șase rânduri literale aici, iar comentariul care le
 * însoțea avertiza exact despre riscul pe care îl crea: „o a doua hartă ar putea diverge
 * tăcut". Exportată în continuare fiindcă `KpiTile` și câteva pagini o cer pe nume.
 *
 * `Object.fromEntries` pierde tipul cheilor, de unde `as` — singurul din fișier, pe o valoare
 * construită chiar aici din `TONE`, care e deja `Record<Tone, …>`.
 */
export const toneClasses = Object.fromEntries(
    (Object.entries(TONE) as [Tone, (typeof TONE)[Tone]][]).map(([tone, style]) => [tone, style.chip]),
) as Record<BadgeTone, string>;

export default function StatusBadge({ tone = 'neutral', children }: { tone?: BadgeTone; children: ReactNode }) {
    return (
        // `relative`: insigna poate purta text `sr-only`, iar acela e `position: absolute`.
        // Fără un strămoș poziționat, el se raportează la blocul de conținut INIȚIAL — adică
        // scapă din orice container care derulează pe deasupra lui. Măsurat pe kanban: textul
        // ascuns al insignei de termen se așeza la coordonata cardului din conținutul
        // NEDERULAT (până la ~1800px), în afara decupării benzii de coloane, și creștea
        // DOCUMENTUL. Pagina derula lateral pe telefon, în gol, în jumătate din încărcări.
        <span className={`relative inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ${toneClasses[tone]}`}>
            {children}
        </span>
    );
}
