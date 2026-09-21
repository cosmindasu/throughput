<?php

/**
 * Traducere franceză a `lang/en/activity.php` — chei IDENTICE, verificate simetric de
 * `php artisan i18n:coverage` (ADR-022, FR-I18N-02/04). Vezi docblock-ul fișierului
 * englez pentru explicația celor patru registre (`actions`/`subjects`/`entries`/`timeline`)
 * și granița FR-I18N-06.
 *
 * Scrisă de asistent, revizuită de proprietar (ADR-022, „Cost, stated honestly"). Pasajele
 * cu risc de ton/idiom sau cu o decizie de formulare netrivială au comentariu „NATIV:"
 * imediat deasupra cheii.
 *
 * NATIV — decizie de formulare pe TOT registrul `entries.*`: engleza compune eticheta din
 * verb + subiect („Created Account", „Exported Deal"), dar `:subject` variază pe ȘAPTE
 * genuri gramaticale diferite (compte/m, affaire/f, commande/f, variante/f, facture/f,
 * produit/m, contact/m, adhésion/f) — un participiu trecut acordat („créé"/„créée") ar fi
 * fals pe jumătate din cazuri, indiferent care formă e aleasă implicit (vezi aceeași
 * capcană, nerezolvată încă, în `lang/fr/enums.php`). Formularea de mai jos evită acordul
 * de gen ÎN LOC să-l aleagă greșit: etichetă-substantiv + „:" + subiect
 * („Création : Compte", „Export : Affaire"), fără verb de acordat. De verificat de
 * proprietar dacă tonul (mai „jurnal de sistem" decât o propoziție completă) e cel dorit.
 */
return [

    'actions' => [
        'created' => 'Créé',
        'updated' => 'Mis à jour',
        'deleted' => 'Supprimé',
        // NATIV: „Login"/„Login Failed" sont des étiquettes courtes de colonne (jurnalul
        // de activitate), pas une phrase — „Connexion" reste correct au masculin, aucun
        // accord requis.
        'login' => 'Connexion',
        'login_failed' => 'Échec de connexion',
        'exported' => 'Exporté',
        'imported' => 'Importé',
        'bulk_action' => 'Action groupée',
        'role_changed' => 'Rôle modifié',
    ],

    'subjects' => [
        'account' => 'Compte',
        'contact' => 'Contact',
        'deal' => 'Affaire',
        'product' => 'Produit',
        'variant' => 'Variante',
        'order' => 'Commande',
        'invoice' => 'Facture',
        // NATIV: « Adhésion » (appartenance à un espace de travail) — à vérifier contre le
        // vocabulaire déjà choisi pour l'écran « Members » (`lang/fr/flash.php:members`,
        // qui parle de « membre », jamais d'« adhésion ») pour rester cohérent si ce mot
        // devient un jour visible directement (aujourd'hui il ne l'est pas : le seul cas
        // qui touche `Membership` a sa propre clé, `entries.member_deactivated`).
        'membership' => 'Adhésion',
        'record' => 'enregistrement',
    ],

    'entries' => [
        // Voir la note „NATIV" en tête de fichier pour le choix de formulation.
        'created' => 'Création : :subject',
        'updated' => 'Modification : :subject',
        'deleted' => 'Suppression : :subject',
        'login' => 'Connexion',
        'login_failed' => 'Échec de connexion',
        'exported' => 'Export : :subject',
        'imported' => 'Import : :subject',
        'bulk_action' => 'Action groupée : :subject',
        'role_changed' => 'Rôle d’un membre modifié',

        'member_deactivated' => 'Membre désactivé',

        'system_actor' => 'Système',
    ],

    'timeline' => [
        // Ici le sujet est FIXE dans la clé (une affaire, une commande) — l'accord du
        // participe passé est donc toujours correct, contrairement à `entries.*` ci-dessus.
        'deal_created' => 'Affaire créée : :title',
        // NATIV: évite d'accorder un verbe sur `:title` (contenu utilisateur, genre
        // inconnu côté serveur) — reformulé en « prochaine étape » plutôt que
        // « déplacée vers ».
        'stage_moved' => 'Nouvelle étape pour :title : :stage',
        'order_placed' => 'Commande passée : :label',

        'fallback_deal' => 'Affaire',
        'fallback_stage' => 'une nouvelle étape',
    ],

];
