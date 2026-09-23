<?php

/**
 * Perechea franceză a lui `lang/en/validation.php` — vezi docblock-ul de acolo pentru de ce
 * fișierul a trebuit publicat din framework și ce trebuie făcut la un upgrade de Laravel.
 *
 * ORDINEA CHEILOR e identică cu a englezei, deliberat: la un upgrade, singurul mod practic
 * de a vedea ce a adăugat Laravel e un diff între `lang/en/validation.php` și fișierul din
 * `vendor/` — iar dacă franceza urmează aceeași ordine, al doilea diff (en ↔ fr) rămâne la
 * fel de citibil. `php artisan i18n:coverage` verifică doar mulțimea de chei, nu ordinea;
 * ordinea e pentru oameni.
 *
 * NATIV: formulările de mai jos sunt cele consacrate ale ecosistemului francez Laravel, nu
 * traduceri libere — un francofon le-a mai întâlnit în alte aplicații, ceea ce aici e un
 * avantaj. Pasajele cu riscul cel mai mare de ton greșit sunt cele care descriu obiecte
 * tehnice pe ecrane adresate unui utilizator obișnuit: `password.uncompromised` („fuite de
 * données"), `uploaded`, `mimes`.
 *
 * `attributes` — POPULAT simetric în ambele limbi de la I18N-01 (P1): până atunci, orice
 * eroare de validare pe un câmp cu nume compus randa numele englez, humanizat („Le champ
 * credit terms est obligatoire."), pe o interfață altfel complet franceză — exact „găurile
 * invizibile" numite mai sus. Cheile provin din `rules()` ale TUTUROR FormRequest-urilor
 * (`app/Http/Requests/**`), plus cele două `Validator::make` manuale din
 * `app/Http/Controllers/Web` — extrase sistematic (nu ochiometric): `ValidationAttributesTest`
 * parcurge prin reflecție toate FormRequest-urile și verifică, pentru fiecare cheie din
 * `rules()`, o intrare `attributes` în AMBELE limbi — gate-ul care ține cataloagele sincrone
 * cu codul, nu doar simetrice între ele. Cheile compuse (`lines.*.discount`,
 * `billing_address.line1`) folosesc notația cu punct pe care Laravel o rezolvă nativ, inclusiv
 * varianta cu wildcard (`Validator::getDisplayableAttribute()` cade pe `cheie.*` dacă
 * `cheie.0` lipsește) — un câmp neacoperit aici cade pe fallback-ul humanizat al numelui de
 * variabilă, corect doar în engleză.
 *
 * Tipografie (revizuit la Valul 5 al Lotului I18N — inversează nota veche, păstrată mai jos
 * ca istoric): spațiu INSECABIL ÎNGUST (U+202F) înaintea lui „:", „;", „?", „!" și „»", și
 * după „«" — convenția majoritară a proiectului (735 de ocurențe deja corecte, măsurate în
 * restul cataloagelor chiar înainte de acest val), nu excepția motivată mai jos. Nota veche
 * pornea de la o premisă greșită: „restul cataloagelor" NU folosea spațiu simplu, ci era deja
 * majoritar pe U+202F — verificat, nu presupus.
 *
 * Compromisul e asumat, nu ascuns: fișierul e publicat din framework (`3af6807`), scris de
 * mână prin comparație cu englezul VERBATIM din `lang/en/validation.php`. Procedura de
 * upgrade descrisă acolo („se adaugă cheile noi în AMBELE limbi") nu impune nicăieri U+202F —
 * `vendor/laravel/framework` nu are oricum o variantă franceză de comparat — deci orice cheie
 * nouă tastată manual, de cineva care nu cunoaște regula asta, va reintroduce spațiul ASCII
 * simplu. Garda e `tests/Feature/I18n/FrenchTypographyTest.php`: dacă pică pe fișierul ăsta
 * după un upgrade viitor, e exact scenariul de mai sus — de renormalizat, nu un bug al gărzii.
 *
 * Nota veche (până la Valul 5, greșită pe premisă — păstrată pentru istoric): „spațiu simplu
 * înaintea lui « : », ca în restul cataloagelor franceze ale proiectului […] — nu spațiu
 * insecabil, care ar fi regula tipografică strictă, dar ar introduce un caracter invizibil
 * într-un fișier pe care cineva îl va compara cândva cu varianta din amonte."
 *
 * Apostrof TIPOGRAFIC (U+2019): mesajele astea se randează în formulare, imediat sub
 * etichete venite din `resources/js/locales/fr/*.json`, care îl folosesc peste tot. Aceeași
 * regulă ca la `lang/fr/imports.php`; `lang/fr/rules.php` folosește forma dreaptă fiindcă
 * acolo regula e consecvența fișierului deja existent.
 */

return [

    'accepted' => 'Le champ :attribute doit être accepté.',
    'accepted_if' => 'Le champ :attribute doit être accepté quand :other vaut :value.',
    'active_url' => 'Le champ :attribute doit être une URL valide.',
    'after' => 'Le champ :attribute doit être une date postérieure au :date.',
    'after_or_equal' => 'Le champ :attribute doit être une date postérieure ou égale au :date.',
    'alpha' => 'Le champ :attribute doit contenir uniquement des lettres.',
    'alpha_dash' => 'Le champ :attribute doit contenir uniquement des lettres, des chiffres, des tirets et des tirets bas.',
    'alpha_num' => 'Le champ :attribute doit contenir uniquement des lettres et des chiffres.',
    'any_of' => 'Le champ :attribute n’est pas valide.',
    'array' => 'Le champ :attribute doit être un tableau.',
    'array_keys' => 'Le champ :attribute doit contenir uniquement les clés suivantes : :values.',
    'ascii' => 'Le champ :attribute doit contenir uniquement des caractères alphanumériques et des symboles sur un octet.',
    'base64' => 'Le champ :attribute doit être une chaîne Base64 valide.',
    'before' => 'Le champ :attribute doit être une date antérieure au :date.',
    'before_or_equal' => 'Le champ :attribute doit être une date antérieure ou égale au :date.',
    'between' => [
        'array' => 'Le champ :attribute doit contenir entre :min et :max éléments.',
        'file' => 'Le champ :attribute doit être compris entre :min et :max kilo-octets.',
        'numeric' => 'Le champ :attribute doit être compris entre :min et :max.',
        'string' => 'Le champ :attribute doit contenir entre :min et :max caractères.',
    ],
    'boolean' => 'Le champ :attribute doit être vrai ou faux.',
    'can' => 'Le champ :attribute contient une valeur non autorisée.',
    'confirmed' => 'La confirmation du champ :attribute ne correspond pas.',
    'contains' => 'Il manque une valeur obligatoire dans le champ :attribute.',
    // Sans `:attribute` en anglais comme en français : la règle `current_password` porte
    // toujours sur le mot de passe, quel que soit le nom du champ.
    'current_password' => 'Le mot de passe est incorrect.',
    'date' => 'Le champ :attribute doit être une date valide.',
    'date_equals' => 'Le champ :attribute doit être une date égale au :date.',
    'date_format' => 'Le champ :attribute doit être au format :format.',
    'decimal' => 'Le champ :attribute doit avoir :decimal décimales.',
    'declined' => 'Le champ :attribute doit être refusé.',
    'declined_if' => 'Le champ :attribute doit être refusé quand :other vaut :value.',
    'different' => 'Les champs :attribute et :other doivent être différents.',
    'digits' => 'Le champ :attribute doit contenir :digits chiffres.',
    'digits_between' => 'Le champ :attribute doit contenir entre :min et :max chiffres.',
    'dimensions' => 'Le champ :attribute a des dimensions d’image non valides.',
    'distinct' => 'Le champ :attribute contient une valeur en double.',
    'doesnt_contain' => 'Le champ :attribute ne doit contenir aucune des valeurs suivantes : :values.',
    'doesnt_end_with' => 'Le champ :attribute ne doit pas se terminer par l’une des valeurs suivantes : :values.',
    'doesnt_start_with' => 'Le champ :attribute ne doit pas commencer par l’une des valeurs suivantes : :values.',
    'email' => 'Le champ :attribute doit être une adresse e-mail valide.',
    'encoding' => 'Le champ :attribute doit être encodé en :encoding.',
    'ends_with' => 'Le champ :attribute doit se terminer par l’une des valeurs suivantes : :values.',
    'enum' => 'La valeur sélectionnée pour :attribute n’est pas valide.',
    'exists' => 'La valeur sélectionnée pour :attribute n’est pas valide.',
    'extensions' => 'Le champ :attribute doit avoir l’une des extensions suivantes : :values.',
    'file' => 'Le champ :attribute doit être un fichier.',
    'filled' => 'Le champ :attribute doit avoir une valeur.',
    'gt' => [
        'array' => 'Le champ :attribute doit contenir plus de :value éléments.',
        'file' => 'Le champ :attribute doit être supérieur à :value kilo-octets.',
        'numeric' => 'Le champ :attribute doit être supérieur à :value.',
        'string' => 'Le champ :attribute doit contenir plus de :value caractères.',
    ],
    'gte' => [
        'array' => 'Le champ :attribute doit contenir au moins :value éléments.',
        'file' => 'Le champ :attribute doit être supérieur ou égal à :value kilo-octets.',
        'numeric' => 'Le champ :attribute doit être supérieur ou égal à :value.',
        'string' => 'Le champ :attribute doit contenir au moins :value caractères.',
    ],
    'hex_color' => 'Le champ :attribute doit être une couleur hexadécimale valide.',
    'image' => 'Le champ :attribute doit être une image.',
    'in' => 'La valeur sélectionnée pour :attribute n’est pas valide.',
    'in_array' => 'Le champ :attribute doit exister dans :other.',
    'in_array_keys' => 'Le champ :attribute doit contenir au moins l’une des clés suivantes : :values.',
    'integer' => 'Le champ :attribute doit être un entier.',
    'ip' => 'Le champ :attribute doit être une adresse IP valide.',
    'ipv4' => 'Le champ :attribute doit être une adresse IPv4 valide.',
    'ipv6' => 'Le champ :attribute doit être une adresse IPv6 valide.',
    'json' => 'Le champ :attribute doit être une chaîne JSON valide.',
    'list' => 'Le champ :attribute doit être une liste.',
    'lowercase' => 'Le champ :attribute doit être en minuscules.',
    'lt' => [
        'array' => 'Le champ :attribute doit contenir moins de :value éléments.',
        'file' => 'Le champ :attribute doit être inférieur à :value kilo-octets.',
        'numeric' => 'Le champ :attribute doit être inférieur à :value.',
        'string' => 'Le champ :attribute doit contenir moins de :value caractères.',
    ],
    'lte' => [
        'array' => 'Le champ :attribute ne doit pas contenir plus de :value éléments.',
        'file' => 'Le champ :attribute doit être inférieur ou égal à :value kilo-octets.',
        'numeric' => 'Le champ :attribute doit être inférieur ou égal à :value.',
        'string' => 'Le champ :attribute ne doit pas contenir plus de :value caractères.',
    ],
    'mac_address' => 'Le champ :attribute doit être une adresse MAC valide.',
    'max' => [
        'array' => 'Le champ :attribute ne doit pas contenir plus de :max éléments.',
        'file' => 'Le champ :attribute ne doit pas dépasser :max kilo-octets.',
        'numeric' => 'Le champ :attribute ne doit pas être supérieur à :max.',
        'string' => 'Le champ :attribute ne doit pas contenir plus de :max caractères.',
    ],
    'max_digits' => 'Le champ :attribute ne doit pas contenir plus de :max chiffres.',
    'mimes' => 'Le champ :attribute doit être un fichier de type : :values.',
    'mimetypes' => 'Le champ :attribute doit être un fichier de type : :values.',
    'min' => [
        'array' => 'Le champ :attribute doit contenir au moins :min éléments.',
        'file' => 'Le champ :attribute doit faire au moins :min kilo-octets.',
        'numeric' => 'Le champ :attribute doit être au moins :min.',
        'string' => 'Le champ :attribute doit contenir au moins :min caractères.',
    ],
    'min_digits' => 'Le champ :attribute doit contenir au moins :min chiffres.',
    'missing' => 'Le champ :attribute doit être absent.',
    'missing_if' => 'Le champ :attribute doit être absent quand :other vaut :value.',
    'missing_unless' => 'Le champ :attribute doit être absent sauf si :other vaut :value.',
    'missing_with' => 'Le champ :attribute doit être absent quand :values est présent.',
    'missing_with_all' => 'Le champ :attribute doit être absent quand :values sont présents.',
    'multiple_of' => 'Le champ :attribute doit être un multiple de :value.',
    'not_in' => 'La valeur sélectionnée pour :attribute n’est pas valide.',
    'not_regex' => 'Le format du champ :attribute n’est pas valide.',
    'numeric' => 'Le champ :attribute doit être un nombre.',
    'password' => [
        'letters' => 'Le champ :attribute doit contenir au moins une lettre.',
        'mixed' => 'Le champ :attribute doit contenir au moins une majuscule et une minuscule.',
        'numbers' => 'Le champ :attribute doit contenir au moins un chiffre.',
        'symbols' => 'Le champ :attribute doit contenir au moins un symbole.',
        // NATIV : la formulation la plus exposée du fichier — elle annonce une fuite de
        // données à quelqu’un qui est en train de choisir un mot de passe. Le ton doit
        // rester factuel, sans alarmisme ni reproche.
        'uncompromised' => 'La valeur du champ :attribute est apparue dans une fuite de données. Veuillez choisir une autre valeur.',
    ],
    'present' => 'Le champ :attribute doit être présent.',
    'present_if' => 'Le champ :attribute doit être présent quand :other vaut :value.',
    'present_unless' => 'Le champ :attribute doit être présent sauf si :other vaut :value.',
    'present_with' => 'Le champ :attribute doit être présent quand :values est présent.',
    'present_with_all' => 'Le champ :attribute doit être présent quand :values sont présents.',
    'prohibited' => 'Le champ :attribute est interdit.',
    'prohibited_if' => 'Le champ :attribute est interdit quand :other vaut :value.',
    'prohibited_if_accepted' => 'Le champ :attribute est interdit quand :other est accepté.',
    'prohibited_if_declined' => 'Le champ :attribute est interdit quand :other est refusé.',
    'prohibited_unless' => 'Le champ :attribute est interdit sauf si :other fait partie de :values.',
    'prohibits' => 'Le champ :attribute interdit la présence de :other.',
    'regex' => 'Le format du champ :attribute n’est pas valide.',
    'required' => 'Le champ :attribute est obligatoire.',
    'required_array_keys' => 'Le champ :attribute doit contenir des entrées pour : :values.',
    'required_if' => 'Le champ :attribute est obligatoire quand :other vaut :value.',
    'required_if_accepted' => 'Le champ :attribute est obligatoire quand :other est accepté.',
    'required_if_declined' => 'Le champ :attribute est obligatoire quand :other est refusé.',
    'required_unless' => 'Le champ :attribute est obligatoire sauf si :other fait partie de :values.',
    'required_with' => 'Le champ :attribute est obligatoire quand :values est présent.',
    'required_with_all' => 'Le champ :attribute est obligatoire quand :values sont présents.',
    'required_without' => 'Le champ :attribute est obligatoire quand :values n’est pas présent.',
    'required_without_all' => 'Le champ :attribute est obligatoire quand aucun de :values n’est présent.',
    'same' => 'Le champ :attribute doit correspondre à :other.',
    'size' => [
        'array' => 'Le champ :attribute doit contenir :size éléments.',
        'file' => 'Le champ :attribute doit faire :size kilo-octets.',
        'numeric' => 'Le champ :attribute doit être égal à :size.',
        'string' => 'Le champ :attribute doit contenir :size caractères.',
    ],
    'starts_with' => 'Le champ :attribute doit commencer par l’une des valeurs suivantes : :values.',
    'string' => 'Le champ :attribute doit être une chaîne de caractères.',
    'timezone' => 'Le champ :attribute doit être un fuseau horaire valide.',
    'unique' => 'La valeur du champ :attribute est déjà utilisée.',
    'uploaded' => 'Le téléversement du champ :attribute a échoué.',
    'uppercase' => 'Le champ :attribute doit être en majuscules.',
    'url' => 'Le champ :attribute doit être une URL valide.',
    'ulid' => 'Le champ :attribute doit être un ULID valide.',
    'uuid' => 'Le champ :attribute doit être un UUID valide.',

    /*
     * Rândul de mai jos e SCHELA din framework, nu text: „attribute-name"/„rule-name" sunt
     * exemple, iar „custom-message" nu se randează niciodată. Păstrat identic cu engleza ca
     * `i18n:coverage` să vadă cele două fișiere simetrice — și NEFOLOSIT de proiect: mesajele
     * proprii per formular stau în `lang/{locale}/forms.php`, fiindcă `custom.<câmp>.<regulă>`
     * e GLOBAL pe numele câmpului, iar „email.required" înseamnă altceva la invitarea unui
     * membru decât în alt formular.
     */
    'custom' => [
        'attribute-name' => [
            'rule-name' => 'custom-message',
        ],
    ],

    // Populat de la I18N-01 — vezi docblock-ul de la începutul fișierului. Aceleași chei,
    // în aceeași ordine, ca în `lang/en/validation.php` (simetrie verificată de
    // `ValidationAttributesTest` și de `php artisan i18n:coverage`).
    'attributes' => [
        'account_id' => 'compte',
        'acknowledge_backorder' => 'confirmation de rupture de stock',
        'active' => 'actif',
        'amount' => 'montant',
        'attributes' => 'attributs',
        'billing_address' => 'adresse de facturation',
        'billing_address.city' => 'ville de l’adresse de facturation',
        'billing_address.country' => 'pays de l’adresse de facturation',
        'billing_address.line1' => 'ligne d’adresse de facturation',
        'billing_address.postal_code' => 'code postal de l’adresse de facturation',
        'billing_address.state' => 'état de l’adresse de facturation',
        'category' => 'catégorie',
        'columns' => 'colonnes',
        'columns.*' => 'colonne',
        'confirm_duplicate_email' => 'confirmation d’e-mail en double',
        'confirmed' => 'confirmé',
        'contact' => 'contact',
        'contact.email' => 'e-mail du contact',
        'contact.first_name' => 'prénom du contact',
        'contact.last_name' => 'nom du contact',
        'contact.phone' => 'téléphone du contact',
        'contact.title' => 'fonction du contact',
        'contact_id' => 'contact',
        'cost' => 'coût',
        'credentials' => 'identifiants',
        'credentials.api_key' => 'clé API',
        'credit_terms' => 'conditions de crédit',
        'currency' => 'devise',
        'deal_id' => 'affaire',
        'delta' => 'changement de quantité',
        'direction' => 'sens',
        'domain' => 'domaine',
        'email' => 'e-mail',
        'expected_close_date' => 'date de clôture prévue',
        'file' => 'fichier',
        'filter' => 'filtre',
        'filter.*' => 'valeur de filtre',
        'first_name' => 'prénom',
        'format' => 'format',
        'from_location_id' => 'emplacement d’origine',
        'ids' => 'enregistrements sélectionnés',
        'ids.*' => 'enregistrement sélectionné',
        'industry' => 'secteur',
        'is_active' => 'actif',
        'is_lost' => 'perdu',
        'is_primary' => 'principal',
        'is_won' => 'gagné',
        'key' => 'clé',
        'last_name' => 'nom',
        'lines' => 'lignes',
        'lines.*' => 'quantité',
        'lines.*.discount' => 'remise de ligne',
        'lines.*.quantity' => 'quantité de ligne',
        'lines.*.unit_price' => 'prix unitaire de ligne',
        'lines.*.variant_id' => 'variante de ligne',
        'locale' => 'langue',
        'location_id' => 'emplacement',
        'lost_reason' => 'motif de perte',
        'low_stock_threshold' => 'seuil de stock faible',
        'mapping' => 'correspondance des colonnes',
        'mapping.*' => 'colonne associée',
        'method' => 'méthode de paiement',
        'mode' => 'mode',
        'name' => 'nom',
        'new_owner_user_id' => 'nouveau propriétaire',
        'note' => 'note',
        'notes' => 'notes',
        'opt_out' => 'désinscription des communications marketing',
        'owner_user_id' => 'propriétaire',
        'paid_at' => 'date de paiement',
        'password' => 'mot de passe',
        'phone' => 'téléphone',
        'price' => 'prix',
        'primary_contact_id' => 'contact principal',
        'probability' => 'probabilité',
        'provider' => 'transporteur',
        'quantity' => 'quantité',
        'reason' => 'motif',
        'reassign' => 'réaffectation',
        'recipients' => 'destinataires',
        'recipients.*' => 'destinataire',
        'report_type' => 'source',
        'resolvedTheme' => 'thème résolu',
        'resource_type' => 'type de ressource',
        'role' => 'rôle',
        'saved_view_id' => 'vue enregistrée',
        'schedule_day' => 'jour de planification',
        'schedule_frequency' => 'fréquence de planification',
        'schedule_time' => 'heure de planification',
        'selectAllMatching' => 'tout sélectionner selon le filtre',
        'shipping_address' => 'adresse de livraison',
        'shipping_address.city' => 'ville de l’adresse de livraison',
        'shipping_address.country' => 'pays de l’adresse de livraison',
        'shipping_address.line1' => 'ligne d’adresse de livraison',
        'shipping_address.postal_code' => 'code postal de l’adresse de livraison',
        'shipping_address.state' => 'état de l’adresse de livraison',
        'sku' => 'SKU',
        'sort' => 'tri',
        'source' => 'source',
        'stage_ids' => 'étapes',
        'stage_ids.*' => 'étape',
        'status' => 'statut',
        'tags' => 'étiquettes',
        'tags.*' => 'étiquette',
        'theme' => 'thème',
        'title' => 'titre',
        'to_location_id' => 'emplacement de destination',
        'to_stage_id' => 'étape cible',
        'token' => 'jeton',
        'unit_of_measure' => 'unité de mesure',
        'value' => 'valeur',
        'visibility' => 'visibilité',
        'weight' => 'poids',
    ],

];
