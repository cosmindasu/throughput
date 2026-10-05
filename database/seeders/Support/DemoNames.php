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
     * Numele de LOCALITATE, compuse din două jumătăți în loc de enumerate: 21 × 20 dau până la
     * 420 de variante plauzibile („Northgate", „Cedarridge", „Riverbrook") din patru rânduri de
     * cod — „până la", fiindcă `buildPool()` aruncă perechile care s-ar dubla („Valval"). Un
     * distribuitor industrial poartă la fel de des numele locului ca pe al fondatorului, deci
     * nu e un artificiu ca să umplem pool-ul.
     *
     * Rostul lor e PRIMUL CUVÂNT. Lista de conturi se sortează alfabetic, deci entropia
     * primului token decide cât de grupat arată ecranul. Măsurat pe seed-ul complet (8.000 de
     * conturi): cu numele de familie singure, 90 de prime cuvinte distincte și 62 de conturi
     * sub cel mai aglomerat dintre ele — primul ecran al listei era un zid de omonime. Cu
     * localitățile adăugate, 924 de prime cuvinte distincte și cel mai mare grup de 19.
     */
    private const PLACE_HEADS = ['North', 'South', 'East', 'West', 'New', 'Old', 'Fair', 'River', 'Lake', 'Oak', 'Cedar', 'Iron', 'Stone', 'Summit', 'Granite', 'Pine', 'Birch', 'Mill', 'High', 'Clear', 'Red'];

    private const PLACE_TAILS = ['gate', 'port', 'ton', 'side', 'view', 'field', 'brook', 'wood', 'dale', 'ridge', 'crest', 'bury', 'haven', 'mere', 'stead', 'march', 'burn', 'ford', 'hill', 'bank'];

    private const PLACE_HEADS_FR = ['Val', 'Mont', 'Bois', 'Pont', 'Roche', 'Champ', 'Beau', 'Haute', 'Clair', 'Grand', 'Saint-', 'Fonte', 'Plaine', 'Vieux', 'Pierre', 'Belle', 'Longue', 'Noire', 'Haut', 'Fresne', 'Orme'];

    private const PLACE_TAILS_FR = ['mont', 'val', 'bourg', 'ville', 'fort', 'lieu', 'rive', 'champ', 'pré', 'cour', 'roche', 'fontaine', 'cluse', 'sierre', 'garde', 'vigne', 'clos', 'puits', 'sault', 'brousse'];

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
        'fasteners' => ['Fasteners', 'Industrial Supply', 'Hardware', 'Bolt & Fastener', 'Fabrication', 'Metal Works', 'Industrial Fasteners', 'Supply Partners', 'Engineered Hardware'],
        'hydraulics' => ['Hydraulics', 'Fluid Power', 'Hose & Fitting', 'Pump Systems', 'Hydraulic Supply', 'Motion Systems', 'Hydraulic Components', 'Power Transmission', 'Fluid Systems'],
        'foodservice' => ['Restaurant Supply', 'Foodservice', 'Catering Equipment', 'Kitchen Systems', 'Food Equipment', 'Culinary Supply', 'Hospitality Supply', 'Kitchen Partners', 'Catering Systems'],
    ];

    /** @var array<string, list<string>> */
    private const VERTICAL_NOUNS_FR = [
        'fasteners' => ['Fixations', 'Fournitures Industrielles', 'Quincaillerie', 'Boulonnerie', 'Visserie', 'Métallerie', 'Fixations Industrielles', 'Assemblage Mécanique', 'Serrurerie Industrielle'],
        'hydraulics' => ['Hydraulique', 'Transmissions Fluides', 'Flexibles et Raccords', 'Systèmes de Pompage', 'Oléohydraulique', 'Composants Hydrauliques', 'Énergie Fluide', 'Vérins et Pompes', 'Hydraulique Industrielle'],
        'foodservice' => ['Équipement CHR', 'Restauration Professionnelle', 'Matériel de Cuisine', 'Grandes Cuisines', 'Fournitures Alimentaires', 'Équipement Culinaire', 'Froid Professionnel', 'Hôtellerie Équipement', 'Cuisines Collectives'],
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
                'name' => self::compose('en:'.$vertical, self::SURNAME_LIKE, self::VERTICAL_NOUNS[$vertical], self::LEGAL_SUFFIXES, self::PLACE_HEADS, self::PLACE_TAILS),
                'industry' => $englishIndustry,
            ];
        }

        return [
            'name' => self::compose('fr:'.$vertical, self::SURNAME_LIKE_FR, self::VERTICAL_NOUNS_FR[$vertical], self::LEGAL_SUFFIXES_FR, self::PLACE_HEADS_FR, self::PLACE_TAILS_FR),
            'industry' => self::VERTICAL_INDUSTRY_FR[$vertical],
        ];
    }

    /**
     * Numele complet: perechea „primul cuvânt + substantiv" trasă FĂRĂ REVENIRE dintr-un pool
     * amestecat o singură dată, plus o formă juridică la întâmplare.
     *
     * Perechea e unitatea de unicitate, nu numele întreg, și asta e o alegere, nu o scurtătură.
     * Un pool construit pe numele întreg (pereche × formă juridică) ar fi avut 25.110 intrări
     * distincte, dar dădea 1.098 de grupuri de felul „Ardmore Foodservice Corp." lângă „Ardmore
     * Foodservice Group" — tehnic nume diferite, citite în listă ca o greșeală de seed. Pe
     * pereche, cele 464 de prime cuvinte englezești × 9 substantive dau 4.176 de combinații per
     * verticală (458 și 4.122 pe franceză, unde patru capete de localitate se dedubleză cu
     * cozile și două cu numele de familie). Peste cele ~2.800 de trageri engleze ale celui mai
     * mare tenant (4.000 de conturi × 70%), deci nicio bază de nume nu se repetă.
     *
     * Varianta anterioară, cu patruzeci de reîncercări aleatoare și un contor la final, eșua
     * exact pe coada densă a pool-ului: 405 din 2.500 de conturi ieșeau cu sufix numeric vizibil
     * („Ardmore Fluid Power Co. #2019"), mai rău decât defectul reparat.
     *
     * @param  string  $cheie  `{limbă}:{verticală}` — dat de apelant, nu derivat din conținutul
     *                         listelor. Varianta cu `md5(implode(...))` recalcula amprenta a
     *                         285 de nume la FIECARE cont: 1,49 µs × 8.000 = 11,9 ms, adică
     *                         aproape tot costul pe care lotul îl adăuga seed-ului.
     * @param  list<string>  $surnames
     * @param  list<string>  $nouns
     * @param  list<string>  $suffixes
     * @param  list<string>  $placeHeads
     * @param  list<string>  $placeTails
     */
    private static function compose(string $cheie, array $surnames, array $nouns, array $suffixes, array $placeHeads, array $placeTails): string
    {

        // Ramura de reumplere e pentru un seed viitor mai mare decât pool-ul: atunci o bază de
        // nume reapare cu altă formă juridică — două societăți înrudite, plauzibil — în loc să
        // se întoarcă un contor vizibil.
        if ((self::$pooluri[$cheie] ?? []) === []) {
            self::$pooluri[$cheie] = self::buildPool($surnames, $nouns, $placeHeads, $placeTails);
        }

        [$lead, $noun] = explode(self::SEP, array_pop(self::$pooluri[$cheie]));

        return $lead.' '.$noun.' '.$suffixes[array_rand($suffixes)];
    }

    /**
     * Perechile, împachetate ca un singur string: la peste 4.100 de intrări per pool, un array de
     * array-uri costă de câteva ori mai multă memorie decât un array de string-uri, iar
     * seeder-ul le ține pe toate în viață cât durează un tenant.
     *
     * @param  list<string>  $surnames
     * @param  list<string>  $nouns
     * @param  list<string>  $placeHeads
     * @param  list<string>  $placeTails
     * @return list<string>
     */
    private static function buildPool(array $surnames, array $nouns, array $placeHeads, array $placeTails): array
    {
        $leads = $surnames;

        foreach ($placeHeads as $head) {
            foreach ($placeTails as $tail) {
                // Două jumătăți identice dau „Valval", „Montmont", „Rocheroche", „Champchamp" —
                // patru nume care nu există în nicio limbă.
                if (strcasecmp($head, $tail) === 0) {
                    continue;
                }

                // După cratimă urmează MAJUSCULĂ: „Saint-Mont", nu „Saint-mont". Singurul cap cu
                // cratimă e „Saint-", dar el singur producea douăzeci din cele douăzeci și patru
                // de nume stricate — iar un cititor francofon vede exact aici că datele sunt
                // generate, adică fix semnalul pe care pool-ul ăsta există ca să-l șteargă.
                $leads[] = $head.(str_ends_with($head, '-') ? ucfirst($tail) : $tail);
            }
        }

        $perechi = [];

        // „Eastwood" e în ambele liste: nume de familie ȘI „East" + „wood". Fără dedupe,
        // perechile lui ar fi stat de două ori în pool și ar fi putut fi trase de două ori.
        foreach (array_unique($leads) as $lead) {
            foreach ($nouns as $noun) {
                $perechi[] = $lead.self::SEP.$noun;
            }
        }

        shuffle($perechi);

        return $perechi;
    }

    /** Separator imposibil într-un nume de firmă, deci sigur pentru `explode()`. */
    private const SEP = "\x1f";

    /** @var array<string, list<string>> perechile încă neconsumate, per limbă și verticală */
    private static array $pooluri = [];

    /** Seed-ul rulează per tenant: altfel al doilea tenant ar porni cu pool-ul deja consumat. */
    public static function resetUniqueness(): void
    {
        self::$pooluri = [];
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
