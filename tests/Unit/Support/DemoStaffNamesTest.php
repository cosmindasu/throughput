<?php

namespace Tests\Unit\Support;

use Database\Seeders\Support\DemoStaffNames;
use PHPUnit\Framework\TestCase;

/**
 * Numele colegilor semănați (`UsersAndMembershipsSeeder`).
 *
 * Fără bază de date: alegerea numelui e logică pură. Testul fixează cele trei lucruri care
 * s-au stricat când numele veneau din `fake()->name()` sau ar putea să se strice dacă
 * pool-ul e editat mai târziu.
 */
class DemoStaffNamesTest extends TestCase
{
    /** marlin 5 + cascade 5 + northgate 5, după `UsersAndMembershipsSeeder::$colleagueSpec`. */
    private const COLLEAGUES_PER_SEED = 15;

    /** §4.2 — cele patru conturi demo scrise de mână; un coleg omonim ar fi derutant în filtrul Owner. */
    private const DEMO_PERSONAS = ['Olivia Sterling', 'Marcus Reyes', 'Priya Anand', 'Evelyn Cho'];

    protected function setUp(): void
    {
        parent::setUp();
        DemoStaffNames::reset();
    }

    public function test_a_full_seed_repeats_neither_a_first_name_nor_a_surname(): void
    {
        $names = [];

        for ($i = 0; $i < self::COLLEAGUES_PER_SEED; $i++) {
            $names[] = DemoStaffNames::next();
        }

        $firsts = array_map(fn (string $n): string => explode(' ', $n)[0], $names);
        $lasts = array_map(fn (string $n): string => explode(' ', $n)[1], $names);

        $this->assertCount(self::COLLEAGUES_PER_SEED, array_unique($names));
        $this->assertCount(
            self::COLLEAGUES_PER_SEED,
            array_unique($firsts),
            'Doi colegi cu același prenume într-o echipă de cinci se observă fără să-i cauți: '.implode(', ', $names)
        );
        $this->assertCount(
            self::COLLEAGUES_PER_SEED,
            array_unique($lasts),
            'Două nume de familie identice: '.implode(', ', $names)
        );
    }

    public function test_no_colleague_can_collide_with_a_demo_persona(): void
    {
        // Jumătățile se trag independent, deci o coliziune e posibilă fix când prenumele
        // personajului e în primul pool ȘI numele lui în al doilea. Se adună AMBELE pool-uri
        // din trageri repetate (mai multe decât mărimea lor) și se verifică toate perechile —
        // un eșantion de nume întregi ar fi găsit coliziunea doar uneori.
        $firsts = [];
        $lasts = [];

        for ($i = 0; $i < 500; $i++) {
            [$first, $last] = explode(' ', DemoStaffNames::next());
            $firsts[$first] = true;
            $lasts[$last] = true;
        }

        foreach (self::DEMO_PERSONAS as $persona) {
            [$first, $last] = explode(' ', $persona);

            $this->assertFalse(
                isset($firsts[$first]) && isset($lasts[$last]),
                sprintf('"%s" e personaj demo — scoate-i prenumele sau numele din pool.', $persona)
            );
        }
    }

    public function test_names_carry_no_title_and_no_character_an_email_cannot_hold(): void
    {
        for ($i = 0; $i < 200; $i++) {
            $name = DemoStaffNames::next();

            // `UsersAndMembershipsSeeder` compune emailul cu `preg_replace('/[^a-z]+/', '.', …)`:
            // orice diacritic ar deveni separator („c.cile.dubois@marlin.internal").
            $this->assertMatchesRegularExpression(
                '/^[A-Z][a-z]+ [A-Z][a-z]+$/',
                $name,
                sprintf('"%s" nu e „Prenume Nume" simplu — titlurile și diacriticele strică și lista, și emailul.', $name)
            );
        }
    }
}
