<?php

namespace Tests\Unit\Support;

use Database\Seeders\Support\DemoNames;
use PHPUnit\Framework\TestCase;

/**
 * FR-I18N-07 (specs.md §15.8, §21.2) — pool-ul paralel de nume și industrii franceze.
 *
 * Fără bază de date: alegerea numelui e logică pură, iar un seed complet (8.000 de
 * conturi pe trei tenanți) ar costa minute ca să verifice ce se vede din 200 de trageri.
 *
 * Ce verifică, în ordinea cerinței:
 *  1. amestecul chiar se întâmplă pe FIECARE dintre cele trei verticale — „distribuit pe
 *     toți cei trei tenanți, nu concentrat pe unul singur" e chiar litera cerinței, iar
 *     un pool francez activ doar pe unul dintre ei ar fi trecut orice test care se uită
 *     la totaluri;
 *  2. numele și industria rămân PERECHE — o firmă cu nume francez și industrie engleză e
 *     mai rău decât una complet englezească;
 *  3. diacriticele se pliază în domeniu, nu se șterg.
 *
 * Numărul de trageri (200) nu e decorativ: la o cotă de 30%, probabilitatea ca o
 * verticală să nu producă NICIUN nume francez în 200 de trageri e sub 10^-30. Testul e
 * deci determinist în practică, deși sursa e aleatoare.
 */
class DemoNamesTest extends TestCase
{
    private const DRAWS = 200;

    /** @var array<string, string> Verticala → industria engleză, ca în `DemoDatasetSeeder::TENANTS`. */
    private const VERTICALS = [
        'fasteners' => 'Industrial Fasteners Distributor',
        'hydraulics' => 'Hydraulic Components Distributor',
        'foodservice' => 'Foodservice Equipment & Supplies',
    ];

    public function test_every_vertical_mixes_both_pools(): void
    {
        foreach (self::VERTICALS as $vertical => $englishIndustry) {
            $industries = [];

            for ($i = 0; $i < self::DRAWS; $i++) {
                $industries[] = DemoNames::account($vertical, $englishIndustry)['industry'];
            }

            $distinct = array_values(array_unique($industries));
            sort($distinct);

            $this->assertCount(
                2,
                $distinct,
                sprintf(
                    'Verticala "%s" a produs o singură industrie în %d trageri (%s) — pool-ul paralel francez nu ajunge la acest tenant (FR-I18N-07).',
                    $vertical,
                    self::DRAWS,
                    implode(' | ', $distinct)
                )
            );

            $this->assertContains($englishIndustry, $distinct);
        }
    }

    public function test_the_french_name_always_comes_with_the_french_industry(): void
    {
        // Sufixele juridice sunt semnalul cel mai sigur al pool-ului din care a venit
        // numele: sunt disjuncte între cele două liste, spre deosebire de substantivele
        // de verticală, unde „Foodservice" ar putea plauzibil apărea în ambele.
        $frenchSuffixes = ['SARL', 'SAS', 'SA', 'SASU', 'et Cie', 'SNC'];

        foreach (self::VERTICALS as $vertical => $englishIndustry) {
            for ($i = 0; $i < self::DRAWS; $i++) {
                ['name' => $name, 'industry' => $industry] = DemoNames::account($vertical, $englishIndustry);

                $isFrenchName = false;
                foreach ($frenchSuffixes as $suffix) {
                    if (str_ends_with($name, ' '.$suffix)) {
                        $isFrenchName = true;
                        break;
                    }
                }

                $this->assertSame(
                    $isFrenchName,
                    $industry !== $englishIndustry,
                    sprintf('"%s" a primit industria "%s" — numele și industria s-au desperecheat.', $name, $industry)
                );
            }
        }
    }

    public function test_accented_company_names_produce_a_readable_domain(): void
    {
        $this->assertSame(
            'bouvierequipementchrsas7.com',
            DemoNames::domain('Bouvier Équipement CHR SAS', 7),
            'Diacriticele trebuie PLIATE, nu șterse — altfel „Équipement" ar da „quipement".'
        );

        $this->assertSame(
            'lemoinemetallerie3.com',
            DemoNames::domain('Lemoine Métallerie', 3)
        );
    }
}
