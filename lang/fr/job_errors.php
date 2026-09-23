<?php

/**
 * Traducere franceză a `lang/en/job_errors.php` — chei IDENTICE, verificate simetric de
 * `php artisan i18n:coverage` (ADR-022, FR-I18N-02/04). Vezi docblock-ul fișierului englez
 * pentru cele cinci registre și pentru `:status` (parametru amânat către `enums.php`, nu
 * text de tradus aici).
 *
 * Scrisă de asistent, de revizuit de proprietar (ADR-022, „Cost, stated honestly").
 * Glosar respectat: Order → commande, Shipment → expédition, Report → rapport,
 * Export → export, Bulk operation → opération groupée, Member → membre.
 */
return [

    'bulk' => [
        'initiator_gone' => 'Le membre qui a lancé cette opération groupée n’est plus disponible.',
        'stuck_operation' => 'Cette opération groupée n’a pas pu démarrer. Veuillez réessayer.',
        'unexpected' => 'Cette opération groupée a échoué en raison d’une erreur inattendue. Réessayez ou contactez le support si le problème persiste.',
    ],

    'shipment' => [
        'generic_failure' => 'Le transporteur n’a pas pu créer d’étiquette. Réessayez ou contactez le support.',
        // `:reason` reste le texte exact du transporteur (souvent en anglais) : seul le cadre est traduit.
        'carrier_rejected' => 'Le transporteur a refusé l’étiquette : :reason',
        // NATIV: cohérent avec `lang/fr/rules.php` (`retry_requires_label_failed`,
        // `discard_requires_label_failed`), qui interpolent déjà `:status` de la même façon.
        'order_no_longer_open' => 'Cette commande n’est plus ouverte à l’expédition (actuellement :status).',
    ],

    'export' => [
        'row_cap_exceeded' => 'Cet export contient maintenant :count lignes ; l’export :format est plafonné à :cap. Utilisez le CSV pour les exports plus volumineux.',
        'zip_not_supported' => 'Cette liste ne peut pas être exportée sous forme d’archive zip. Utilisez le CSV à la place.',
        'list_failed' => 'Cet export n’a pas pu être terminé. Réessayez depuis la liste.',
        'unexpected' => 'Cet export a échoué en raison d’une erreur inattendue. Réessayez ou contactez le support si le problème persiste.',
    ],

    'gdpr_export' => [
        'nothing_delivered' => 'L’export n’a pas pu être terminé. Rien n’a été livré ; demandez un nouvel export pour réessayer.',
        'packaging_failed' => 'L’export n’a pas pu être empaqueté. Rien n’a été livré ; demandez un nouvel export pour réessayer.',
        'could_not_start' => 'L’export n’a pas pu démarrer. Réessayez.',
        'entity_write_failed' => 'L’export s’est arrêté pendant l’écriture du fichier « :entity ». Rien n’a été livré ; demandez un nouvel export pour réessayer.',
    ],

    'report' => [
        'definition_missing' => 'La définition de ce rapport n’existe plus.',
        'pdf_row_cap_exceeded' => 'Ce rapport contient :count lignes ; le PDF est plafonné à :cap. Utilisez le CSV ou le XLSX pour les rapports plus volumineux.',
        'xlsx_row_cap_exceeded' => 'Ce rapport contient :count lignes ; le XLSX est plafonné à :cap. Utilisez le CSV pour les rapports plus volumineux.',
        'generation_failed' => 'Ce rapport n’a pas pu être généré. Réessayez de le relancer.',
        'unexpected' => 'Ce rapport a échoué en raison d’une erreur inattendue. Réessayez ou contactez le support si le problème persiste.',
    ],

    'webhook' => [
        'no_customer' => 'Pas pour ce déploiement : l’événement n’a pas de data.object.customer, il ne peut donc appartenir à aucun espace de travail.',
        'unknown_customer' => 'Pas pour ce déploiement : le client Stripe :customer n’appartient à aucun espace de travail ici. Le bac à sable Stripe est partagé avec un autre projet, ses événements arrivent donc aussi à ce point de terminaison.',
    ],

];
