<?php

/**
 * Traducere FRANCEZĂ, scrisă de asistent — de REVIZUIT de proprietar înainte de public
 * (ADR-022, secțiunea „Cost, stated honestly"), la fel ca restul `lang/fr/`. Pasajele
 * marcate „DE VERIFICAT" sunt notele explicative — text nou, cu nuanță juridică GDPR, nu
 * doar terminologie de business.
 *
 * Oglindă EXACTĂ, cheie cu cheie, a `lang/en/gdpr.php` — `php artisan i18n:coverage` pică
 * dacă vreo cheie lipsește aici sau e orfană (FR-I18N-02).
 *
 * Etichetele reiau LITERAL formulările deja stabilite pe ecranele vecine, ca să nu apară o
 * a doua traducere pentru același obiect (vezi docblock-ul din `lang/en/gdpr.php`):
 * „Comptes"/„Contacts"/„Affaires" din `lang/fr/search.php` (`groups.*`) și
 * `resources/js/locales/fr/common.json` (`nav.*`), „Commandes"/„Factures"/„Journal
 * d’activité" tot din `nav.*`, „Paiements" din `resources/js/locales/fr/invoices.json`
 * (`payments.heading`).
 */
return [

    'sources' => [
        'accounts' => [
            'label' => 'Comptes',
            // DE VERIFICAT
            'note' => 'Chaque compte de cet espace de travail. L’adresse de facturation, l’adresse de livraison et les étiquettes sont des objets structurés, c’est pourquoi cette entité est uniquement au format JSON — une colonne de tableur les aurait aplaties en texte.',
        ],

        'contacts' => [
            'label' => 'Contacts',
            // DE VERIFICAT
            'note' => 'Chaque personne enregistrée pour un compte. Les contacts anonymisés au titre du droit à l’effacement n’apparaissent pas ici : leurs champs identifiants ont déjà été effacés, la ligne qui reste ne comporte donc aucune donnée personnelle à remettre.',
        ],

        'deals' => [
            'label' => 'Affaires',
            // DE VERIFICAT
            'note' => 'Chaque affaire, y compris celles supprimées du tableau : une affaire supprimée reste stockée, ce qui en fait toujours une donnée vous concernant. Ces lignes portent une date "deleted_at" ; les affaires actives l’ont vide.',
        ],

        'orders' => [
            'label' => 'Commandes',
            // DE VERIFICAT
            'note' => 'Chaque commande, avec ses lignes imbriquées sous "order_lines" dans le fichier JSON. Le CSV ne contient que les lignes de commande — une ligne par commande, sans le détail des lignes, car un tableau ligne par ligne répéterait le total de chaque commande.',
        ],

        'invoices' => [
            'label' => 'Factures',
            // DE VERIFICAT
            'note' => 'Chaque facture émise pour une commande. Le PDF généré ne fait pas partie de l’archive — il s’agit d’un rendu de ces mêmes montants, et un PDF ne compte pas comme un format structuré et lisible par machine aux fins de portabilité.',
        ],

        'payments' => [
            'label' => 'Paiements',
            // DE VERIFICAT
            'note' => 'Chaque paiement enregistré pour une facture. Les paiements sont saisis manuellement dans ce produit, il n’y a donc aucune donnée de carte ou bancaire à exporter.',
        ],

        'activity_log' => [
            'label' => 'Journal d’activité',
            // DE VERIFICAT — „[anonymized]" reste LITÉRAL, dans les deux langues : c’est un
            // marqueur technique écrit dans les données elles-mêmes
            // (`App\Jobs\System\AnonymizeActivityLogJob::PLACEHOLDER`), pas un texte
            // d’interface à traduire.
            'note' => 'Qui a modifié quoi, et quand. Les entrées antérieures à la période de conservation ont leurs valeurs avant/après remplacées par "[anonymized]" — la forme de l’entrée subsiste, pas les valeurs. Uniquement en JSON, car ces valeurs avant/après sont des objets structurés.',
        ],
    ],

];
