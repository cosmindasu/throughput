<?php

/**
 * Traducere franceză a `lang/en/flash.php` — chei IDENTICE, verificate simetric de
 * `php artisan i18n:coverage` (ADR-022, FR-I18N-02/04). Vezi docblock-ul fișierului
 * englez pentru explicația structurii de chei și a mecanismului de pluralizare.
 *
 * Scrisă de asistent, revizuită de proprietar (ADR-022, „Cost, stated honestly"). Pasajele
 * cu risc de ton/idiom sau cu o decizie de formulare netrivială au comentariu „NATIV:"
 * imediat deasupra cheii — vezi și raportul lotului pentru lista completă.
 */
return [

    'accounts' => [
        'created' => 'Compte créé.',
        'updated' => 'Compte mis à jour.',
        'deleted' => 'Compte supprimé.',

        'deletion_blocked' => 'Ce compte ne peut pas être supprimé : il a :parts.',
        'deletion_blocked_deals_clause' => ':count affaire|:count affaires',
        // Pluralizată AICI, deși engleza (sursa) e invariantă pe acest string — acordul
        // „supprimée/supprimées" depinde de `:deleted`, un subset separat de `:count` de
        // mai sus (vezi comentariul din `lang/en/flash.php`).
        'deletion_blocked_deleted_note' => '(:deleted supprimée, conservée pour l’historique du pipeline)|(:deleted supprimées, conservées pour l’historique du pipeline)',
        'deletion_blocked_orders_clause' => ':count commande|:count commandes',
    ],

    'contacts' => [
        'created' => 'Contact créé.',
        'updated' => 'Contact mis à jour.',
        'deleted' => 'Contact supprimé.',
        'anonymized' => 'Contact anonymisé — il est référencé par des affaires ou des commandes, donc ses données personnelles ont été supprimées et la fiche a été conservée.',
    ],

    'deals' => [
        'created' => 'Affaire créée.',
        'updated' => 'Affaire mise à jour.',
        'deleted' => 'Affaire supprimée.',
    ],

    'orders' => [
        'created' => 'Commande créée.',
        'updated' => 'Commande mise à jour.',
        'deleted' => 'Commande supprimée.',
        'confirmed' => 'Commande confirmée.',
        'cancelled' => 'Commande annulée.',

        'shipments' => [
            'created' => 'Expédition créée — son étiquette est en cours de génération.',
            'discarded' => 'Expédition abandonnée.',
            'marked_shipped' => 'Expédition marquée comme expédiée.',
            'retrying_label' => 'Nouvelle tentative de génération de l’étiquette d’expédition.',
        ],
    ],

    'stock' => [
        'received' => 'Stock réceptionné.',
        'adjusted' => 'Stock ajusté.',
        'transferred' => 'Stock transféré.',
    ],

    'products' => [
        'created' => 'Produit créé.',
        'updated' => 'Produit mis à jour.',
        'deleted' => 'Produit supprimé.',

        'deletion_blocked' => 'Ce produit ne peut pas être supprimé : il a :parts.',
        'deletion_blocked_stock_history_clause' => ':count variante avec un historique de stock|:count variantes avec un historique de stock',
        'deletion_blocked_used_on_orders_clause' => ':count variante utilisée sur des commandes|:count variantes utilisées sur des commandes',

        'variants' => [
            'created' => 'Variante créée.',
            'updated' => 'Variante mise à jour.',
            'deleted' => 'Variante supprimée.',

            'deletion_blocked_stock_movements' => 'Cette variante ne peut pas être supprimée : elle a des mouvements de stock enregistrés.',
            'deletion_blocked_order_lines' => 'Cette variante ne peut pas être supprimée : elle est utilisée sur au moins une commande.',
        ],
    ],

    'invoices' => [
        'created' => 'Facture créée.',
        'marked_sent' => 'Facture marquée comme envoyée.',
        // NATIV: „voided" a une nuance comptable précise (facture annulée/avoir) — à
        // vérifier contre la terminologie déjà choisie pour `invoice.status` côté
        // frontend (hors périmètre de ce lot), pour rester cohérent sur tout l’écran.
        'voided' => 'Facture annulée.',
        'pdf_not_ready' => 'Le PDF de cette facture n’est pas encore prêt.',
        'regenerating_pdf' => 'Nouvelle génération du PDF en cours.',
    ],

    'payments' => [
        'recorded' => 'Paiement enregistré.',
    ],

    'imports' => [
        'uploaded' => 'Fichier importé — associez les colonnes pour continuer.',
        'mapping_saved' => 'Association enregistrée.',
        'validating' => 'Validation en arrière-plan — cette page se met à jour automatiquement.',
        'importing' => 'Import des lignes valides en arrière-plan — cette page se met à jour automatiquement.',
        'cancelled' => 'Import annulé.',
    ],

    'reports' => [
        'created' => 'Rapport créé.',
        'updated' => 'Rapport mis à jour.',
        'deleted' => 'Rapport supprimé.',
        'queued' => 'Rapport mis en file d’attente — cette page se mettra à jour automatiquement.',
    ],

    'stages' => [
        'created' => 'Étape ajoutée.',
        'updated' => 'Étape mise à jour.',
        'deleted' => 'Étape supprimée.',
        'reordered' => 'Ordre des étapes mis à jour.',

        'deletion_blocked_deals_present' => 'Cette étape contient :count affaire. Déplacez-la vers une autre étape avant de la supprimer.|Cette étape contient :count affaires. Déplacez-les vers une autre étape avant de la supprimer.',
        'deletion_blocked_deal_history' => 'Des affaires sont déjà passées par cette étape, et leur historique d’étapes y fait encore référence, donc elle ne peut pas être supprimée. Vous pouvez la renommer à la place.',
        'deletion_blocked_fk' => 'Cette étape est encore utilisée par des affaires ou leur historique d’étapes et ne peut pas être supprimée.',
    ],

    'bulk' => [
        'reassign_owner_started' => 'Opération groupée démarrée — cette page se met à jour automatiquement.',
        'cancel_draft_orders_started' => 'Annulation des commandes brouillon en cours — cette page se met à jour automatiquement.',
        'update_price_started' => 'Mise à jour des prix en cours — cette page se met à jour automatiquement.',
        'set_active_started' => 'Mise à jour du statut des produits en cours — cette page se met à jour automatiquement.',

        'operation_missing' => 'Cette opération n’existe plus.',
        'cancelling_in_progress' => 'Annulation en cours — les lignes déjà en cours se termineront, les autres s’arrêtent.',
        'cancelled' => 'Annulée.',
        'already_finished' => 'Cette opération est déjà terminée.',

        'groups' => [
            'cancelling' => 'Annulation de toutes les opérations de ce groupe — les lignes déjà en cours se termineront, les autres s’arrêtent.',
        ],
    ],

    'unassigned' => [
        'reassigning' => 'Réattribution de tous les enregistrements non attribués — cette page se met à jour automatiquement.',
    ],

    'saved_views' => [
        'default_team_view_deleted' => 'La vue « Team » que vous utilisiez par défaut a été supprimée.',
    ],

    'members' => [
        // NATIV: nom du rôle (`:role`) volontairement NON traduit — vient d’un autre lot
        // (`app/Support/Permissions`), voir le commentaire dans `lang/en/flash.php`. À
        // vérifier que „Owner/Manager/Agent/Viewer" affichés tels quels, au milieu d’une
        // phrase française, restent lisibles — sinon la phrase devra être restructurée
        // une fois ce lot livré.
        'role_updated' => ':name est maintenant :role dans cet espace de travail.',

        // Reformulé sur « l’accès » plutôt que « :name a été désactivé(e) », délibérément
        // — évite l’accord de genre sur un nom de personne dont le genre n’est pas connu
        // côté serveur (aucune donnée de genre dans `users`).
        'deactivated' => 'L’accès de :name a été désactivé.',
        'deactivated_with_open_records' => 'L’accès de :name a été désactivé. :count enregistrement a besoin d’un nouveau propriétaire — voir Non attribué.|L’accès de :name a été désactivé. :count enregistrements ont besoin d’un nouveau propriétaire — voir Non attribué.',
        'deactivating_with_reassignment' => 'Désactivation de l’accès de :name et réattribution de ses dossiers ouverts — cette page se met à jour automatiquement.',

        'fallback_name' => 'Ce membre',
    ],

    'invitations' => [
        'sent_new_user' => 'Invitation envoyée à :email. Cette personne a 7 jours pour l’accepter.',
        'sent_existing_user' => 'Invitation envoyée à :email. Cette personne a déjà un compte Throughput, l’acceptation ne prend donc qu’un clic.',
        'resent' => 'Un nouveau lien d’invitation a été envoyé à :email. L’ancien lien ne fonctionne plus.',
        'revoked' => 'L’invitation envoyée à :email a été révoquée. Son lien ne fonctionne plus.',

        'email_fallback_invited' => 'l’adresse invitée',
        'email_fallback_generic' => 'cette adresse',

        // NATIV: „You're in" est un idiome familier anglais (accès accordé, ton
        // enjoué) — la formulation ci-dessous est une approximation raisonnable, pas une
        // traduction littérale ; à valider par un locuteur natif pour le ton voulu.
        'accepted' => 'Vous y êtes — bienvenue chez :tenant.',
    ],

    'api_tokens' => [
        'created' => 'Jeton API créé. Copiez-le maintenant — il ne sera plus affiché ensuite.',
        'already_revoked' => 'Ce jeton était déjà révoqué.',
        'revoked' => 'Jeton API révoqué. Toute intégration qui l’utilisait cesse de fonctionner immédiatement.',
        'revoked_all' => ':count jeton API révoqué. Toute intégration qui l’utilisait cesse de fonctionner immédiatement.|:count jetons API révoqués. Toute intégration qui les utilisait cesse de fonctionner immédiatement.',
        'revoked_all_none' => 'Il n’y avait aucun jeton API actif à révoquer.',
    ],

    'carrier_settings' => [
        'updated' => 'Paramètres de transporteur mis à jour.',
    ],

    'data_export' => [
        'queued' => 'Votre export de données est en file d’attente. Vous recevrez un email quand l’archive sera prête.',
        'file_unavailable' => 'Ce fichier d’export n’est plus disponible. Demandez un nouvel export.',
    ],

    'exports' => [
        'started' => 'Export démarré — cette page se mettra à jour automatiquement.',
        'demo_limit_exceeded' => 'Cet export dépasse la limite de la démo et ne peut pas être démarré.',
        // `:format` non traduit — voir la note dans `lang/en/flash.php`.
        'pdf_row_cap_exceeded' => 'Cet export contient :total ligne ; l’export :format est plafonné à :cap. Utilisez le CSV pour les exports plus volumineux.|Cet export contient :total lignes ; l’export :format est plafonné à :cap. Utilisez le CSV pour les exports plus volumineux.',
    ],

    'subscription' => [
        'read_only' => 'Votre abonnement est impayé — mettez à jour votre moyen de paiement pour retrouver un accès complet.',
    ],

    'demo' => [
        'workspace_delete_disabled' => 'La suppression d’un espace de travail est désactivée dans la démo publique. Les données de démo sont réinitialisées chaque nuit à 03h00 UTC.',
        'members_deactivate_disabled' => 'La désactivation d’un membre est désactivée dans la démo publique — ce sont des identifiants partagés utilisés par d’autres visiteurs.',
        // NATIV: „Owner" non traduit, cohérent avec `members.role_updated` ci-dessus.
        'members_change_role_disabled' => 'Le changement de rôle d’un membre est désactivé dans la démo publique — ce sont des identifiants partagés utilisés par d’autres visiteurs. (Un espace de travail doit toujours garder au moins un :owner actif.)',
        'members_remove_disabled' => 'La suppression d’un membre est désactivée dans la démo publique — ce sont des identifiants partagés utilisés par d’autres visiteurs. (Un espace de travail doit toujours garder au moins un :owner actif.)',
        'api_tokens_revoke_all_disabled' => 'La révocation de tous les jetons API en une fois est désactivée dans la démo publique. Révoquez les jetons un par un à la place.',
        'subscription_cancel_disabled' => 'L’annulation de l’abonnement est désactivée dans la démo publique. La facturation fonctionne en mode test Stripe ici, donc il n’y a rien de réel à annuler.',
    ],

    'common' => [
        'list_and' => 'et',
    ],

];
