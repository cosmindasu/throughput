<?php

namespace Database\Seeders\Support;

/**
 * Numele colegilor semănați per tenant (`UsersAndMembershipsSeeder`).
 *
 * Până acum veneau din `fake()->name()`, care lipește titluri academice și sufixe
 * genealogice: zece din cei cincisprezece ieșeau „Prof. Keegan Wilderman III", „Mr.
 * Brannon Lang DVM", „Dr. Jayde Kertzmann". Nu e o problemă de gust — numele astea stau în
 * filtrul Owner, în jurnalul de activitate, ca proprietar pe conturi și pe avatarele din
 * kanban, chiar lângă cele patru persoane demo scrise de mână („Olivia Sterling", „Marcus
 * Reyes"). Contrastul dintre ele e exact semnalul că datele sunt generate.
 *
 * Pool-ul e INTERNAȚIONAL deliberat, ca `DemoNames::SURNAME_LIKE`: produsul se vinde pe
 * piața internațională, iar o echipă cu cincisprezece nume dintr-o singură țară ar fi o
 * afirmație despre client pe care demo-ul n-are de ce s-o facă.
 *
 * Fără diacritice, și asta nu e o scăpare: `UsersAndMembershipsSeeder` compune emailul din
 * nume cu `preg_replace('/[^a-z]+/', '.', …)`, care ar transforma „é" în separator și ar
 * scoate „c.cile.dubois@marlin.internal". Numele alese se citesc firesc și fără ele.
 */
final class DemoStaffNames
{
    /** Patru prenume lipsesc deliberat — Olivia, Marcus, Priya, Evelyn sunt personajele demo (§4.2). */
    private const FIRST = [
        'Amara', 'Anton', 'Bianca', 'Caleb', 'Camille', 'Dario', 'Delphine', 'Elena',
        'Felix', 'Gabriel', 'Hannah', 'Ines', 'Jonas', 'Karim', 'Lena', 'Lucas',
        'Mateo', 'Nadia', 'Oscar', 'Rosa', 'Samir', 'Sofia', 'Theo', 'Tomas',
        'Vera', 'Yusuf',
    ];

    /** La fel: Sterling, Reyes, Anand și Cho lipsesc, ca o pereche trasă la întâmplare să nu recompună un personaj demo. */
    private const LAST = [
        'Almeida', 'Bauer', 'Brandt', 'Caron', 'Dubois', 'Eriksen', 'Fontana', 'Gallo',
        'Haddad', 'Ibrahim', 'Jensen', 'Kowalski', 'Laurent', 'Moreau', 'Nilsson', 'Oyelaran',
        'Petrov', 'Quintana', 'Rossi', 'Salazar', 'Tanaka', 'Vargas', 'Weber', 'Zielinski',
    ];

    /** @var list<string> prenumele încă neconsumate, amestecate o singură dată */
    private static array $prenume = [];

    /** @var list<string> numele de familie încă neconsumate */
    private static array $familie = [];

    /**
     * Următorul nume: prenumele și numele de familie se trag SEPARAT și fără revenire.
     *
     * Nu perechea, ci fiecare jumătate — și asta e diferența care contează. Trăgând perechi
     * dintr-un produs cartezian de 624, cei cincisprezece colegi ai unui seed complet ieșeau
     * cu nume întregi distincte, dar cu „Yusuf Bauer", „Yusuf Brandt" și „Yusuf Rossi"
     * printre ei: într-o echipă de cinci oameni, doi omonimi pe prenume sunt exact genul de
     * detaliu pe care îl observi fără să-l cauți. Cu 26 de prenume și 24 de nume de familie
     * pentru 15 colegi, nici prenumele, nici numele nu se repetă în tot seed-ul.
     *
     * Unicitatea se ține pe TOT seed-ul, nu per tenant (spre deosebire de
     * `DemoNames::resetUniqueness()`): Owner-ul e membru în toate cele trei organizații și
     * comută între ele din bara de sus, deci ar vedea același coleg în două companii.
     */
    public static function next(): string
    {
        if (self::$prenume === []) {
            self::$prenume = self::FIRST;
            shuffle(self::$prenume);
        }

        if (self::$familie === []) {
            self::$familie = self::LAST;
            shuffle(self::$familie);
        }

        return array_pop(self::$prenume).' '.array_pop(self::$familie);
    }

    /** Seed-ul poate rula de mai multe ori în același proces (teste) — pool-urile repornesc pline. */
    public static function reset(): void
    {
        self::$prenume = [];
        self::$familie = [];
    }
}
