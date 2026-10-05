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
