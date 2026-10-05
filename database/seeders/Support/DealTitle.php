<?php

namespace Database\Seeders\Support;

use Illuminate\Support\Carbon;

/**
 * Titlul unei afaceri din setul demo.
 *
 * Înainte, `DealFactory` alegea din OPT șiruri fixe. La 1.375 de afaceri per tenant asta
 * însemna fiecare titlu repetat de vreo 172 de ori — iar pe lista de Deals se vedeau șase
 * „Annual supply agreement" pe UN singur ecran. Kanbanul masca problema (arată puține carduri
 * odată); lista nu.
 *
 * Titlul se compune acum din trei bucăți, și NU include numele clientului: acela e deja o
 * coloană alături, iar repetarea lui ar fi făcut rândul mai lung fără să adauge informație.
 * Partea care lipsea era CE se vinde — categoria din catalogul tenantului. Asta face și
 * titlurile potrivite pe fiecare demo în parte: „Annual Hose supply agreement" la un
 * distribuitor de hidraulică, „Quarterly Bolts restock" la unul de elemente de fixare.
 */
final class DealTitle
{
    /** `:category` e singurul substituent; restul e text care trebuie să sune a limbă vie. */
    private const SHAPES = [
        'Annual :category supply agreement',
        'Quarterly :category restock',
        ':category for new facility',
        'Expanded :category line',
        ':category replacement contract',
        'Volume discount renewal — :category',
        'Emergency :category reorder',
        ':category rollout — new location',
        ':category standardisation programme',
        'Framework agreement — :category',
        ':category trial order',
        'Spare :category stocking programme',
    ];

    /**
     * Perioada se ia din DATA afacerii, nu la întâmplare: o oportunitate din 2025 care spune
     * „FY27" se citește imediat ca date inventate. Se adaugă doar pe o parte din titluri —
     * pusă pe toate, ar deveni ea însăși un tipar.
     *
     * @param  list<string>  $categories  categoriile catalogului ACESTUI tenant
     */
    public static function compose(array $categories, Carbon $at): string
    {
        $category = $categories === [] ? 'supplies' : $categories[array_rand($categories)];
        $title = str_replace(':category', $category, self::SHAPES[array_rand(self::SHAPES)]);

        if (random_int(1, 100) > 45) {
            return $title;
        }

        $year = (int) $at->format('Y');

        return $title.' — '.match (random_int(1, 3)) {
            1 => 'Q'.$at->quarter.' '.$year,
            2 => 'FY'.substr((string) ($year + 1), 2),
            default => (string) $year,
        };
    }
}
