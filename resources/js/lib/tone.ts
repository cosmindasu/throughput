/**
 * O SINGURĂ hartă tentă → clase, în loc de patru (`StatusBadge.toneClasses`, `KpiTile.toneValue`,
 * `KpiTile.toneEdge`, punctul de status) care pot diverge. Fiecare șir e LITERAL (Tailwind
 * scanează sursa; `bg-${tone}-tint` nu s-ar genera) și folosește exclusiv tokeni.
 *
 * Contrastul, măsurat pe tokenii din `app.css`: fiecare tentă ca text/icon pe propria tentă
 * (chip), pe `--surface` și pe `--row-alt`/`--row-hover`/`--row-selected` e ≥ 4,75:1 în ambele
 * teme, deci iconurile trec și pragul de text (4,5:1), nu doar cel non-text (3:1).
 */
export type Tone = 'neutral' | 'accent' | 'success' | 'warning' | 'danger' | 'info';

interface ToneStyle {
    /** Fundal tentat + text/icon: perechea măsurată, folosită pe chip-uri și insigne. */
    chip: string;
    /** Text sau icon gol, pe orice suprafață a aplicației. */
    text: string;
    /** Bara plină de pe muchia unei plăci. */
    edge: string;
    /** Punct de status. */
    dot: string;
    /** Pentru atribute SVG (`stroke`, `fill`), unde nu există clase. */
    css: string;
}

/**
 * Tentele de chip sunt TOKENS FIXE, nu `color-mix()` peste suprafața curentă (regula din
 * `.ai/rules/frontend.md`): amestecată per context, tenta de eroare cobora de la 4,74:1
 * la 4,17:1 pe `--raised`.
 *
 * `neutral.chip` e singurul ton fără semnal cromatic, deci primește unul geometric: pe tema
 * deschisă `--raised` (#f6f9fb) e practic indistinct de `--surface` ȘI de dunga `--row-alt`
 * (#f7fafc), așa că un chip „inactive" dispărea complet dintr-un tabel — vizibil în auditul
 * din 2026-09-29, lângă „active"/„prospect" care se citeau imediat. `ring`, nu `border`: nu
 * ocupă spațiu, deci toate chip-urile rămân de aceeași înălțime.
 */
export const TONE: Record<Tone, ToneStyle> = {
    neutral: { chip: 'bg-raised text-text-2 ring-1 ring-inset ring-border', text: 'text-text-2', edge: 'border-l-control', dot: 'bg-control', css: 'var(--control)' },
    accent: { chip: 'bg-accent-tint text-accent-text', text: 'text-accent-text', edge: 'border-l-accent-fill', dot: 'bg-accent-fill', css: 'var(--accent-text)' },
    success: { chip: 'bg-success-tint text-success', text: 'text-success', edge: 'border-l-success', dot: 'bg-success', css: 'var(--success)' },
    warning: { chip: 'bg-warning-tint text-warning', text: 'text-warning', edge: 'border-l-warning', dot: 'bg-warning', css: 'var(--warning)' },
    danger: { chip: 'bg-danger-tint text-danger', text: 'text-danger', edge: 'border-l-danger', dot: 'bg-danger', css: 'var(--danger)' },
    info: { chip: 'bg-info-tint text-info', text: 'text-info', edge: 'border-l-info', dot: 'bg-info', css: 'var(--info)' },
};
