import type { DealsBoardColumn } from '@/types/generated';

/**
 * Culoarea fiecărei etape, într-un singur loc: graficul de pipeline din dashboard, iar la
 * pasul următor kanbanul și insigna din lista de afaceri. Etapele deschise primesc rampa
 * secvențială `--stage-1…4` (mai închis = mai aproape de câștig), Won și Lost își păstrează
 * semantica de stare (`--success`/`--danger`) — altfel „pierdut" ar primi o nuanță de pe
 * rampa de progres și ar arăta ca penultima etapă.
 *
 * Valorile sunt CSS (`var(--…)`), nu clase Tailwind: se dau în `style`/atribute SVG, unde
 * singura variantă e o valoare de culoare. Rampa are 4 trepte măsurate; un pipeline cu mai
 * multe etape deschise reciclează ultima, deliberat — o a cincea treaptă ar trebui să încapă
 * între două trepte existente, deci s-ar distinge mai prost de vecini decât o repetiție.
 */
export function stageColors(stages: Array<{ id: string; isWon: boolean; isLost: boolean }>): Map<string, string> {
    const colors = new Map<string, string>();
    let openIndex = 0;

    for (const stage of stages) {
        if (stage.isWon) {
            colors.set(stage.id, 'var(--success)');
        } else if (stage.isLost) {
            colors.set(stage.id, 'var(--danger)');
        } else {
            colors.set(stage.id, `var(--stage-${Math.min(openIndex + 1, 4)})`);
            openIndex++;
        }
    }

    return colors;
}

export interface PipelineSummary {
    openValue: number;
    /** Valoarea ponderată cu probabilitatea fiecărei etape — ce se așteaptă să se încaseze, nu ce e pe masă. */
    weightedValue: number;
    openCount: number;
    /** `null` când nu s-a închis încă nimic: 0% ar afirma că se pierde tot. */
    winRate: number | null;
}

/**
 * Sinteza din antetul board-ului, calculată din ACELEAȘI coloane pe care pagina le desenează
 * — nu o a doua interogare. Două motive: n-ar putea diverge de ce se vede, și se recalculează
 * singură după o mutare optimistă, înainte ca serverul să confirme.
 *
 * Etapele terminale intră doar în rata de câștig; „deschis" înseamnă deschis.
 */
export function summarizePipeline(columns: DealsBoardColumn[]): PipelineSummary {
    let openValue = 0;
    let weightedValue = 0;
    let openCount = 0;
    let won = 0;
    let lost = 0;

    for (const column of columns) {
        if (column.stage.isWon) {
            won += column.total;
        } else if (column.stage.isLost) {
            lost += column.total;
        } else {
            openValue += column.valueTotal;
            weightedValue += (column.valueTotal * (column.stage.probability ?? 0)) / 100;
            openCount += column.total;
        }
    }

    return { openValue, weightedValue, openCount, winRate: won + lost > 0 ? won / (won + lost) : null };
}
