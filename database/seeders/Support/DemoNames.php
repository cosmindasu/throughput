<?php

namespace Database\Seeders\Support;

/**
 * Nume plauzibile de firme B2B (specs.md §21.2): "[Nume] [Industrie] [Sufix legal]",
 * niciodată "Test 1" / "Company A". Un pool mic dar combinatoric suficient de variat.
 */
final class DemoNames
{
    private const SURNAME_LIKE = [
        'Whitfield', 'Delgado', 'Kessler', 'Marchetti', 'Okafor', 'Sundstrom', 'Baptiste',
        'Ferraro', 'Lindqvist', 'Okonkwo', 'Vantage', 'Harrow', 'Castellan', 'Novak', 'Ashworth',
        'Brennan', 'Calloway', 'Dupree', 'Eastwood', 'Farrow', 'Gallant', 'Hargrove', 'Ibarra',
        'Jarrett', 'Kincaid', 'Lombard', 'Mercer', 'Nakamura', 'Osei', 'Pemberton', 'Quintero',
        'Rutherford', 'Sinclair', 'Thackeray', 'Underwood', 'Vasquez', 'Wexford', 'Yarrow', 'Zimmer',
        'Ardmore', 'Blackwood', 'Cresswell', 'Draycott', 'Elmhurst', 'Fenwick',
    ];

    private const LEGAL_SUFFIXES = ['Co.', 'Inc.', 'LLC', 'Ltd.', 'Group', 'Corp.'];

    /** @var array<string, list<string>> */
    private const VERTICAL_NOUNS = [
        'fasteners' => ['Fasteners', 'Industrial Supply', 'Hardware', 'Bolt & Fastener', 'Fabrication', 'Metal Works'],
        'hydraulics' => ['Hydraulics', 'Fluid Power', 'Hose & Fitting', 'Pump Systems', 'Hydraulic Supply', 'Motion Systems'],
        'foodservice' => ['Restaurant Supply', 'Foodservice', 'Catering Equipment', 'Kitchen Systems', 'Food Equipment', 'Culinary Supply'],
    ];

    public static function company(string $vertical): string
    {
        $surname = self::SURNAME_LIKE[array_rand(self::SURNAME_LIKE)];
        $nouns = self::VERTICAL_NOUNS[$vertical];
        $noun = $nouns[array_rand($nouns)];
        $suffix = self::LEGAL_SUFFIXES[array_rand(self::LEGAL_SUFFIXES)];

        return "{$surname} {$noun} {$suffix}";
    }

    /** Domeniu plauzibil, unic prin sufixul numeric furnizat de apelant (nu Faker::unique()). */
    public static function domain(string $company, int $uniqueSuffix): string
    {
        $slug = strtolower((string) preg_replace('/[^a-z0-9]/i', '', $company));

        return substr($slug, 0, 24).$uniqueSuffix.'.com';
    }
}
