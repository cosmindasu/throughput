<?php

namespace Database\Seeders\Support;

/**
 * Nume plauzibile de firme B2B (specs.md §21.2): "[Nume] [Industrie] [Sufix legal]",
 * niciodată "Test 1" / "Company A". Un pool mic dar combinatoric suficient de variat.
 *
 * **Pool paralel francez (FR-I18N-07, Valul 4 al Lotului I18N, ADR-022).** Alături de
 * pool-ul englez, nu în locul lui: un evaluator care comută interfața pe franceză ar
 * vedea altfel un chrome tradus peste date exclusiv anglofone — exact discrepanța pe
 * care §21.2 („nume plauzibile", nu „Test 1") o exclude deja pentru engleză.
 *
 * Trei lucruri fixate deliberat aici, nu lăsate la voia apelantului:
 *
 *  1. **Amestecul se face la nivel de CONT, pe toți cei trei tenanți** — cerut explicit
 *     de FR-I18N-07 („distribuit pe toți cei trei tenanți, nu concentrat pe unul
 *     singur"). Nu există un „tenant francez": un tenant monolingv ar fi arătat
 *     segregarea datelor, nu bilingvismul produsului, iar cine deschide demo-ul pe
 *     primul workspace n-ar fi văzut niciodată vreun nume francez.
 *  2. **Numele și industria vin ÎMPREUNĂ.** `accounts.industry` era până acum o
 *     constantă per tenant (`DemoDatasetSeeder::TENANTS`). O firmă cu nume francez și
 *     industrie engleză ar fi fost mai rău decât o firmă complet englezească — de aceea
 *     `account()` întoarce perechea, nu doar numele.
 *  3. **Numele rămân fixe după inserare** (FR-I18N-06/07): nu se retraduc la comutarea
 *     limbii interfeței. Pool-ul rezolvă credibilitatea INIȚIALĂ a datelor, nu o
 *     traducere dinamică — asta ar fi fost o afirmație falsă despre ce face aplicația.
 */
final class DemoNames
{
    /**
     * Cota de conturi generate din pool-ul francez, în procente.
     *
     * 30, nu 50: suficient ca fiecare tenant să aibă nume franceze pe prima pagină a
     * oricărei liste (la scara E2E, 40 de conturi per tenant, înseamnă ~12), dar nu atât
     * cât demo-ul citit pe engleză — implicitul, deci primul lucru pe care îl vede
     * oricine — să pară pe jumătate netradus. Amestecul e ce se demonstrează; o paritate
     * exactă n-ar demonstra nimic în plus.
     */
    private const FRENCH_SHARE_PERCENT = 30;

    private const SURNAME_LIKE = [
        'Whitfield', 'Delgado', 'Kessler', 'Marchetti', 'Okafor', 'Sundstrom', 'Baptiste',
        'Ferraro', 'Lindqvist', 'Okonkwo', 'Vantage', 'Harrow', 'Castellan', 'Novak', 'Ashworth',
        'Brennan', 'Calloway', 'Dupree', 'Eastwood', 'Farrow', 'Gallant', 'Hargrove', 'Ibarra',
        'Jarrett', 'Kincaid', 'Lombard', 'Mercer', 'Nakamura', 'Osei', 'Pemberton', 'Quintero',
        'Rutherford', 'Sinclair', 'Thackeray', 'Underwood', 'Vasquez', 'Wexford', 'Yarrow', 'Zimmer',
        'Ardmore', 'Blackwood', 'Cresswell', 'Draycott', 'Elmhurst', 'Fenwick',
    ];

    /** Simetric ca mărime cu pool-ul englez, ca varietatea combinatorică să fie aceeași. */
    private const SURNAME_LIKE_FR = [
        'Bouvier', 'Lemoine', 'Delaunay', 'Marchand', 'Fontaine', 'Rochefort', 'Vasseur',
        'Chevalier', 'Beaumont', 'Duchamp', 'Leclerc', 'Marceau', 'Perrin', 'Thibault',
        'Vaillant', 'Aubert', 'Bertrand', 'Dumont', 'Fournier', 'Granger', 'Imbert',
        'Jouvet', 'Lacroix', 'Montclair', 'Noirot', 'Ollivier', 'Pasquier', 'Quesnel',
        'Rimbaud', 'Sauvage', 'Tessier', 'Urbain', 'Valmont', 'Villeneuve', 'Andrieu',
        'Baudry', 'Carpentier', 'Desmarais', 'Estienne', 'Favreau', 'Gaillard', 'Hervieu',
        'Joubert', 'Lanvin', 'Mercier',
    ];

    private const LEGAL_SUFFIXES = ['Co.', 'Inc.', 'LLC', 'Ltd.', 'Group', 'Corp.'];

    /**
     * Formele juridice reale din Franța. „Groupe" lipsește deliberat, deși ar fi fost
     * traducerea directă a lui „Group": în franceză stă ÎNAINTEA numelui („Groupe
     * Bouvier"), nu după, iar tiparul „[Nume] [Industrie] [Formă]" e comun ambelor
     * pool-uri. „et Cie" îi ține locul — se așază corect la coadă și e la fel de
     * plauzibil pe o firmă de distribuție.
     */
    private const LEGAL_SUFFIXES_FR = ['SARL', 'SAS', 'SA', 'SASU', 'et Cie', 'SNC'];

    /** @var array<string, list<string>> */
    private const VERTICAL_NOUNS = [
        'fasteners' => ['Fasteners', 'Industrial Supply', 'Hardware', 'Bolt & Fastener', 'Fabrication', 'Metal Works'],
        'hydraulics' => ['Hydraulics', 'Fluid Power', 'Hose & Fitting', 'Pump Systems', 'Hydraulic Supply', 'Motion Systems'],
        'foodservice' => ['Restaurant Supply', 'Foodservice', 'Catering Equipment', 'Kitchen Systems', 'Food Equipment', 'Culinary Supply'],
    ];

    /** @var array<string, list<string>> */
    private const VERTICAL_NOUNS_FR = [
        'fasteners' => ['Fixations', 'Fournitures Industrielles', 'Quincaillerie', 'Boulonnerie', 'Visserie', 'Métallerie'],
        'hydraulics' => ['Hydraulique', 'Transmissions Fluides', 'Flexibles et Raccords', 'Systèmes de Pompage', 'Oléohydraulique', 'Composants Hydrauliques'],
        'foodservice' => ['Équipement CHR', 'Restauration Professionnelle', 'Matériel de Cuisine', 'Grandes Cuisines', 'Fournitures Alimentaires', 'Équipement Culinaire'],
    ];

    /**
     * Perechea franceză a industriilor din `DemoDatasetSeeder::TENANTS` — aceeași
     * descriere, nu una diferită: e acelalși tenant, văzut de un client francofon.
     *
     * @var array<string, string>
     */
    private const VERTICAL_INDUSTRY_FR = [
        'fasteners' => 'Distributeur de fixations industrielles',
        'hydraulics' => 'Distributeur de composants hydrauliques',
        'foodservice' => 'Équipements et fournitures pour la restauration',
    ];

    /**
     * Numele și industria unui cont semănat, din pool-ul englez sau din cel francez.
     *
     * `$englishIndustry` vine din configul tenantului (`DemoDatasetSeeder::TENANTS`) și
     * rămâne sursa de adevăr pentru cazul englez — pool-ul francez NU îl redefinește,
     * doar îi oferă o pereche când numele e francez.
     *
     * @return array{name: string, industry: string}
     */
    public static function account(string $vertical, string $englishIndustry): array
    {
        if (! Rand::bool(self::FRENCH_SHARE_PERCENT)) {
            return [
                'name' => self::compose(self::SURNAME_LIKE, self::VERTICAL_NOUNS[$vertical], self::LEGAL_SUFFIXES),
                'industry' => $englishIndustry,
            ];
        }

        return [
            'name' => self::compose(self::SURNAME_LIKE_FR, self::VERTICAL_NOUNS_FR[$vertical], self::LEGAL_SUFFIXES_FR),
            'industry' => self::VERTICAL_INDUSTRY_FR[$vertical],
        ];
    }

    /**
     * @param  list<string>  $surnames
     * @param  list<string>  $nouns
     * @param  list<string>  $suffixes
     */
    private static function compose(array $surnames, array $nouns, array $suffixes): string
    {
        return $surnames[array_rand($surnames)]
            .' '.$nouns[array_rand($nouns)]
            .' '.$suffixes[array_rand($suffixes)];
    }

    /** Domeniu plauzibil, unic prin sufixul numeric furnizat de apelant (nu Faker::unique()). */
    public static function domain(string $company, int $uniqueSuffix): string
    {
        $slug = strtolower((string) preg_replace('/[^a-z0-9]/i', '', self::foldAccents($company)));

        return substr($slug, 0, 24).$uniqueSuffix.'.com';
    }

    /**
     * Diacriticele se PLIAZĂ, nu se șterg — „Métallerie" trebuie să dea „metallerie", nu
     * „mtallerie". Fără pasul ăsta, filtrul `[^a-z0-9]` de mai sus ar fi mâncat pur și
     * simplu fiecare literă accentuată, iar pool-ul francez ar fi produs domenii
     * ciuntite: exact genul de detaliu care face datele demo să pară generate, adică
     * fix ce §21.2 cere să nu se întâmple. Nu `iconv('ASCII//TRANSLIT')`: rezultatul lui
     * depinde de locale-ul și de implementarea de `iconv` a mașinii, deci ar fi putut
     * diferi între dezvoltare și container.
     */
    private static function foldAccents(string $value): string
    {
        return strtr($value, [
            'à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ã' => 'a', 'å' => 'a',
            'ç' => 'c', 'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
            'î' => 'i', 'ï' => 'i', 'í' => 'i', 'ì' => 'i',
            'ô' => 'o', 'ö' => 'o', 'ó' => 'o', 'ò' => 'o', 'õ' => 'o',
            'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ú' => 'u',
            'ÿ' => 'y', 'ñ' => 'n', 'œ' => 'oe', 'æ' => 'ae',
            'À' => 'A', 'Â' => 'A', 'Ä' => 'A', 'Á' => 'A', 'Ã' => 'A', 'Å' => 'A',
            'Ç' => 'C', 'È' => 'E', 'É' => 'E', 'Ê' => 'E', 'Ë' => 'E',
            'Î' => 'I', 'Ï' => 'I', 'Í' => 'I', 'Ì' => 'I',
            'Ô' => 'O', 'Ö' => 'O', 'Ó' => 'O', 'Ò' => 'O', 'Õ' => 'O',
            'Ù' => 'U', 'Û' => 'U', 'Ü' => 'U', 'Ú' => 'U',
            'Ÿ' => 'Y', 'Ñ' => 'N', 'Œ' => 'OE', 'Æ' => 'AE',
        ]);
    }
}
