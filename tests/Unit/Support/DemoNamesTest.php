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

    /** `DemoDatasetSeeder::TENANTS['marlin']['accounts']` la scara 1.0 — plafonul real. */
    private const LARGEST_TENANT_ACCOUNTS = 4000;

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

    /**
     * Pool-ul trebuie să acopere cel mai mare tenant FĂRĂ să repete baza unui nume și fără
     * să cadă pe vreun artificiu de unicitate.
     *
     * Testul ăsta există pentru că prima încercare de a mări varietatea numelor a eșuat
     * exact aici: `compose()` trăgea la întâmplare și reîncerca de patruzeci de ori la
     * coliziune, ceea ce merge până pe ultima treime a pool-ului și apoi nu mai merge —
     * 405 din 2.500 de conturi ieșeau cu „#2019" lipit la coadă. Al doilea defect era mai
     * subtil: un pool mare construit pe numele ÎNTREG (pereche × formă juridică) dădea
     * nume tehnic distincte, dar „Ardmore Foodservice Corp." stătea în listă chiar lângă
     * „Ardmore Foodservice Group", de 1.098 de ori. De aceea unitatea de unicitate
     * verificată aici e BAZA numelui, nu numele complet.
     *
     * 4.000 e numărul de conturi al tenantului `marlin` din `DemoDatasetSeeder::TENANTS`,
     * la scara 1.0 — adică plafonul real, nu o cifră rotundă aleasă comod. Se trage pe
     * fiecare verticală, nu doar pe una, pentru că pool-ul e separat per verticală și per
     * limbă: un singur pool subdimensionat ar fi trecut un test care se uită la totaluri.
     */
    public function test_the_largest_tenant_draws_without_repeating_a_name_base(): void
    {
        $legalSuffixes = ['Co.', 'Inc.', 'LLC', 'Ltd.', 'Group', 'Corp.', 'SARL', 'SAS', 'SA', 'SASU', 'et Cie', 'SNC'];

        foreach (self::VERTICALS as $vertical => $englishIndustry) {
            DemoNames::resetUniqueness();

            $bases = [];

            for ($i = 0; $i < self::LARGEST_TENANT_ACCOUNTS; $i++) {
                $name = DemoNames::account($vertical, $englishIndustry)['name'];

                $this->assertDoesNotMatchRegularExpression(
                    '/[#\x00-\x1f]/',
                    $name,
                    sprintf('"%s" conține un artificiu de unicitate — pool-ul s-a epuizat și a căzut pe o rezervă vizibilă.', $name)
                );

                $bases[] = preg_replace('/ ('.implode('|', array_map('preg_quote', $legalSuffixes)).')$/', '', $name);
            }

            $repetate = array_filter(array_count_values($bases), fn (int $n): bool => $n > 1);

            $this->assertSame(
                [],
                $repetate,
                sprintf(
                    'Verticala "%s" a repetat %d baze de nume în %d trageri (de ex. %s) — pool-ul e mai mic decât cel mai mare tenant.',
                    $vertical,
                    count($repetate),
                    self::LARGEST_TENANT_ACCOUNTS,
                    implode(', ', array_slice(array_keys($repetate), 0, 3))
                )
            );
        }

        DemoNames::resetUniqueness();
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
