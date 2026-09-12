<?php

namespace Database\Seeders\Support;

/**
 * Utilitare de randomizare pentru seed-ul de volum (plan §7.8). Evită `Faker::unique()`
 * în buclele fierbinți (accounts/orders/order_lines etc.) — la zeci de mii de apeluri,
 * generatorul de unicitate al Faker devine costul dominant fără să aducă vreun beneficiu
 * aici (unicitatea o garantăm noi, cu contoare, unde chiar contează).
 */
final class Rand
{
    /**
     * Alege o cheie dintr-o hartă cheie => pondere (procente, nu neapărat însumate la 100).
     *
     * @param  array<int|string, int|float>  $weights
     */
    public static function weightedKey(array $weights): int|string
    {
        $total = array_sum($weights);
        $roll = mt_rand(1, (int) round($total * 1000)) / 1000;
        $accumulated = 0.0;

        foreach ($weights as $key => $weight) {
            $accumulated += $weight;

            if ($roll <= $accumulated) {
                return $key;
            }
        }

        return array_key_last($weights);
    }

    /** Adevărat cu probabilitate `$percentTrue` din 100. */
    public static function bool(int $percentTrue): bool
    {
        return random_int(1, 100) <= $percentTrue;
    }

    /** Sumă nerotundă (specs.md §21.2) într-un interval, cu 2 zecimale. */
    public static function money(float $min, float $max): float
    {
        return round($min + mt_rand() / mt_getrandmax() * ($max - $min), 2);
    }

    /**
     * Alege `$count` elemente distincte dintr-o listă indexată numeric, prin index.
     *
     * @param  list<mixed>  $items
     * @return list<mixed>
     */
    public static function distinct(array $items, int $count): array
    {
        $count = min($count, count($items));
        $indexes = array_rand($items, max($count, 1));

        if (! is_array($indexes)) {
            $indexes = [$indexes];
        }

        return array_map(static fn (int $i) => $items[$i], $indexes);
    }
}
