<?php

/**
 * Traducere FRANCEZĂ, scrisă de asistent — de REVIZUIT de proprietar înainte de public
 * (ADR-022, secțiunea „Cost, stated honestly"). Pasajele marcate mai jos cu „DE VERIFICAT"
 * sunt cele mai expuse la o traducere mecanică: terminologie de business (stoc, comenzi,
 * pipeline de vânzări), nu gramatică simplă.
 *
 * Oglindă EXACTĂ, cheie cu cheie, a `lang/en/rules.php` — `php artisan i18n:coverage`
 * pică dacă vreo cheie lipsește aici sau e orfană (FR-I18N-02).
 *
 * Pluralizare: Laravel alege SINGULAR pentru `fr` și la 0, și la 1
 * (`MessageSelector::getPluralIndex('fr', $number)` → `($number == 0 || $number == 1) ? 0 : 1`,
 * cablat nativ în framework) — sintaxa simplă `'singular|plural'` de mai jos e suficientă,
 * fără intervale explicite `{0}`/`{1}`/`[2,*]`.
 */
return [

    'stock' => [
        // DE VERIFICAT — terminologie de stoc.
        'negative_on_hand' => 'Il ne reste que :count unité en stock à cet emplacement ; cette modification ferait passer le stock sous zéro.|Il ne reste que :count unités en stock à cet emplacement ; cette modification ferait passer le stock sous zéro.',
        'insufficient_at_source' => 'Il ne reste que :count unité en stock à l’emplacement source.|Il ne reste que :count unités en stock à l’emplacement source.',
        // DE VERIFICAT — formulation aussi laconique que l’anglais d’origine (sans « unité(s) »).
        'transfer_available_at_source' => 'Il n’y a que :available en stock à l’emplacement source.',
    ],

    'orders' => [
        'cannot_cancel' => 'Cette commande ne peut pas être annulée depuis son statut actuel (:status).',
        'has_shipment' => 'Cette commande a une expédition et ne peut plus être annulée.',
        'cannot_confirm' => 'Cette commande ne peut pas être confirmée depuis son statut actuel (:status).',
        'account_required' => 'Cette commande a besoin d’un compte valide avant de pouvoir être confirmée.',
        'lines_required' => 'Ajoutez au moins une ligne avant de confirmer cette commande.',
        // DE VERIFICAT — „backorder" n-are echivalent scurt consacrat în franceza de
        // business; „rupture différée" e o alegere explicativă, nu un termen standard.
        'backorder_confirmation_required' => 'Une ou plusieurs lignes dépassent le stock disponible. Confirmez explicitement pour passer cette commande en rupture différée (backorder).',
        'cannot_transition' => 'Cette commande ne peut pas passer à :target depuis son statut actuel (:status).',
        // DE VERIFICAT — « sous-total » reprend le terme déjà établi dans
        // `lang/fr/pdf.php` (`subtotal` → « Sous-total »).
        'discount_exceeds_line_subtotal' => 'La remise ne peut pas dépasser le sous-total de la ligne (:subtotal).',
    ],

    'deals' => [
        // TRANȘAT (proprietarul, 2026-09-21) — „deal" se traduce „affaire", peste tot.
        // Întrebarea fusese lăsată deschisă aici, la Valul 2; Valul 3 a scos la iveală că
        // între timp apăruseră AMBELE forme, aproape în proporții egale: 35 de ocurențe
        // „affaire" (acest fișier, `flash.php`, `mail.php`, plus cataloagele i18next ale
        // ecranelor de conturi/contacte/activitate) față de 30 „opportunité"
        // (`reports.php` și cataloagele de deals/orders/bulk/search/navigație). Un evaluator
        // francofon ar fi văzut două cuvinte pentru același obiect, pe ecrane vecine.
        // Cele 30 au fost convertite; nu mai există a doua formă nicăieri.
        'stage_wrong_pipeline' => 'Cette étape n’appartient pas au pipeline de cette affaire.',
        'already_on_stage' => 'Cette affaire est déjà à cette étape.',
        'value_required_for_won' => 'Définissez une valeur pour l’affaire avant de la marquer comme Gagnée',
        'lost_reason_required' => 'Sélectionnez un motif avant de marquer cette affaire comme Perdue.',
    ],

    'pipeline' => [
        'duplicate_stage_order' => 'L’ordre des étapes ne peut pas répéter deux fois la même étape.',
        'stage_order_mismatch' => 'L’ordre des étapes doit lister chaque étape de ce pipeline, une seule fois, et aucune autre.',
        'both_won_and_lost' => 'Une étape ne peut pas être marquée à la fois Gagnée et Perdue.',
        'name_taken' => 'Une étape nommée ":name" existe déjà dans ce pipeline.',
        'flag_taken' => 'Ce pipeline a déjà une étape marquée comme :label.',
        'flags' => [
            'won' => 'Gagnée',
            'lost' => 'Perdue',
        ],
    ],

    'shipments' => [
        'invalid_status_for_creation' => 'Les expéditions ne peuvent être créées que depuis une commande confirmée ou partiellement honorée (actuellement :status).',
        'choose_quantity' => 'Choisissez une quantité supérieure à zéro sur au moins une ligne.',
        'line_not_in_order' => 'L’une des lignes sélectionnées n’appartient plus à cette commande.',
        'remaining_to_ship' => 'Il ne reste que :count unité à expédier sur cette ligne.|Il ne reste que :count unités à expédier sur cette ligne.',
        'discard_requires_label_failed' => 'Seule une expédition dont l’étiquette a échoué peut être abandonnée (actuellement :status).',
        'mark_shipped_requires_label_purchased' => 'Seule une expédition avec une étiquette achetée peut être marquée comme expédiée (actuellement :status).',
        'no_lines' => 'Cette expédition n’a aucune ligne.',
        'insufficient_stock' => 'Stock insuffisant pour expédier ceci : :available en stock, :requested demandé(s).',
        'retry_requires_label_failed' => 'Seule une expédition dont l’étiquette a échoué peut être retentée (actuellement :status).',
        'line_no_room' => 'Cette ligne n’a plus de place pour cette expédition — abandonnez-la et créez-en une nouvelle pour ce qu’il reste réellement.',
    ],

    'invoices' => [
        'invalid_status_for_creation' => 'Une facture ne peut être créée qu’à partir d’une commande confirmée ou honorée (statut actuel : :status).',
        'already_has_active' => 'Cette commande a déjà une facture active. Annulez-la avant d’en créer une nouvelle.',
        'only_draft_can_be_sent' => 'Seule une facture brouillon peut être marquée comme envoyée.',
        'only_sent_or_overdue_can_receive_payment' => 'Un paiement ne peut être enregistré que pour une facture envoyée ou en retard.',
        'payment_exceeds_balance' => 'Ce paiement (:amount) dépasse le solde restant (:balance).',
        'already_void' => 'Cette facture est déjà annulée.',
    ],

    'shipping' => [
        // DE VERIFICAT — „transporteur" pentru „carrier" (Shippo); termen standard de
        // logistică, dar de confirmat în contextul UI-ului deja tradus la Val 3.
        'api_key_required' => 'Ajoutez une clé API Shippo avant d’activer ce transporteur.',
        'sandbox_key_only' => 'Seules les clés sandbox Shippo (shippo_test_...) sont acceptées sur ce déploiement — jamais une clé live.',
    ],

    // Voir `lang/en/rules.php` — règle réutilisée par les contacts ET les affaires.
    'contacts' => [
        'account_not_in_workspace' => 'Sélectionnez un compte de cet espace de travail.',
        'primary_requires_account' => 'Un contact principal doit appartenir à un compte.',
        'email_already_linked' => 'Cet e-mail est déjà lié à :account.',
    ],

    'imports' => [
        'concurrency_limit' => 'Cet espace de travail a déjà un import en cours. Terminez-le ou attendez sa fin avant d’en démarrer un autre (un seul import actif par espace de travail).',
        'already_finished' => 'Cet import est déjà terminé et ne peut plus être annulé.',
        // DE VERIFICAT — „finalisé" ales deliberat, NU „validé" (deja folosit de statusul
        // tehnic `validated` al importului) — evită coliziunea de vocabular între „a
        // finaliza un import" și „a valida un import" (pas anterior în același flux).
        'cannot_commit' => 'Cet import ne peut pas être finalisé depuis son statut actuel.',
        'cannot_validate' => 'Cet import ne peut pas démarrer sa validation depuis son statut actuel.',
        'processing_in_background' => 'Cet import est actuellement traité en arrière-plan — attendez la fin avant de modifier le mappage.',
        'row_limit_exceeded' => 'Ce fichier contient :rows lignes, ce qui dépasse la limite de :max_rows lignes par import. Divisez-le en fichiers plus petits et importez-les un par un.',
        'no_data_rows' => 'Ce fichier ne contient aucune ligne de données à importer — seulement un en-tête (ou rien du tout).',
    ],

    'gdpr' => [
        'export_already_running' => 'Cet espace de travail a déjà un export de données en cours. Attendez sa fin avant d’en demander un autre.',
    ],

    // Voir `lang/en/rules.php` pour le contexte — validation de SENS sur `saved_view_id`.
    'reports' => [
        'saved_view_not_found' => 'Cette vue enregistrée est introuvable.',
        'saved_view_forbidden' => 'Vous n’avez pas accès à cette vue enregistrée.',
        // DE VERIFICAT — `:type` est le type technique de la vue enregistrée
        // (`saved_views.resource_type`, ex. `deals`), pas un mot français ; la phrase
        // reste correcte quel que soit le type inséré.
        'saved_view_unsupported_type' => 'Les vues enregistrées sur ":type" ne peuvent pas encore être utilisées comme source de rapport.',
    ],

    'bulk' => [
        'role_cap_exceeded' => 'Cette opération toucherait :count ligne, au-dessus de la limite de :limit lignes par opération pour votre rôle.|Cette opération toucherait :count lignes, au-dessus de la limite de :limit lignes par opération pour votre rôle.',
        'demo_row_cap' => 'Cette opération toucherait :count ligne. La démo publique limite les opérations en masse à :cap lignes.|Cette opération toucherait :count lignes. La démo publique limite les opérations en masse à :cap lignes.',
        'confirmation_required' => 'Cette opération toucherait :count ligne et nécessite une confirmation avant de démarrer.|Cette opération toucherait :count lignes et nécessite une confirmation avant de démarrer.',
        'concurrency_limit' => 'Vous avez déjà :limit opération en masse en cours. Attendez qu’elle se termine (ou annulez-la) avant d’en démarrer une autre.|Vous avez déjà :limit opérations en masse en cours. Attendez qu’elles se terminent (ou annulez-les) avant d’en démarrer une autre.',
    ],

    /*
     * Vezi `lang/en/rules.php` pentru rolul acestui bloc și pentru de ce `:owner`/`:manager`
     * nu sunt scrise literal.
     *
     * ACORD DE GEN — constrângerea de care depind frazele de mai jos, de verificat dacă se
     * schimbă vreodată un nume de rol: „un autre :owner" și „le dernier :owner" funcționează
     * fiindcă singurele roluri interpolate aici sunt « Propriétaire » și « Gestionnaire »,
     * amândouă substantive epicene care primesc „un/le". Un nume de rol feminin ar cere
     * „une autre"/„la dernière" și ar rupe fraza tăcut. Dacă se ajunge acolo, soluția e
     * ancorarea pe „le rôle :owner" (invariabil, masculin), cum face deja
     * `lang/fr/mail.php` la `membership_records_need_new_owner.footer` — nu alegerea unui
     * gen la nimereală.
     *
     * „Ce membre est déjà désactivé." — acord pe substantivul « membre », masculin
     * gramatical, deci corect indiferent de persoana reală. Diferit DELIBERAT de
     * `lang/fr/mail.php`, care scrie „:member a été désactivé(e)": acolo subiectul e NUMELE
     * persoanei, aici e substantivul comun.
     *
     * Terminologie preluată, nu reinventată: « espace de travail » (29 de ocurențe în
     * cataloagele existente) și fraza „au moins un :owner", deja prezentă la
     * `flash.php:members_change_role_disabled`.
     */
    'members' => [
        'no_workspace' => 'Ce compte n’est membre d’aucun espace de travail.',
        'cannot_invite' => 'Vous ne pouvez pas inviter de membres dans cet espace de travail.',
        'owner_invites_owner' => 'Seul un :owner peut inviter un autre :owner.',
        'invitation_not_pending' => 'Cette invitation n’est plus en attente.',
        'invitation_expired' => 'Cette invitation a expiré. Demandez-en une nouvelle.',
        'invitation_invalid' => 'Cette invitation n’est plus valide. Demandez-en une nouvelle.',
        'cannot_change_roles' => 'Vous ne pouvez pas modifier les rôles dans cet espace de travail.',
        'owner_changes_owner' => 'Seul un :owner peut promouvoir ou rétrograder un autre :owner.',
        'last_owner_required' => 'Un espace de travail doit avoir au moins un :owner.',
        'cannot_deactivate' => 'Vous ne pouvez pas désactiver de membres dans cet espace de travail.',
        'owner_deactivates_owner' => 'Seul un :owner peut désactiver un autre :owner.',
        'already_deactivated' => 'Ce membre est déjà désactivé.',
        'transfer_ownership_first' => 'Transférez la propriété avant de désactiver le dernier :owner.',
        'cannot_deactivate_self' => 'Vous ne pouvez pas vous désactiver vous-même. Demandez à un autre :owner ou :manager de le faire.',
        'no_longer_a_member' => 'Ce membre n’existe plus dans cet espace de travail.',
        // Accord sur « personne » (féminin) — le sujet grammatical de la phrase anglaise
        // ("That person...") est un nom commun, pas le nom de l'invité·e.
        'already_a_member' => 'Cette personne est déjà membre de cet espace de travail.',
        'invitation_already_pending' => 'Une invitation est déjà en attente pour cette adresse. Renvoyez-la ou révoquez-la depuis la liste ci-dessous.',
        // `owner_not_active`/`new_owner_is_target` — voir `lang/en/rules.php` pour le
        // contexte exact (trois appelantes pour la première clé). « Propriétaire » ici
        // désigne le propriétaire d’un ENREGISTREMENT (`owner_user_id`), pas le rôle
        // Propriétaire ci-dessus — formulation déjà établie dans
        // `resources/js/locales/fr/help.json` (« un propriétaire qui n’est plus membre
        // actif »).
        'owner_not_active' => 'Le propriétaire sélectionné n’est pas membre actif de cet espace de travail.',
        'new_owner_is_target' => 'Le nouveau propriétaire ne peut pas être le membre en cours de désactivation.',
    ],

];
