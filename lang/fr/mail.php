<?php

/**
 * Catalog FR — pereche exactă (aceleași chei „dot") cu `lang/en/mail.php`, verificată de
 * `php artisan i18n:coverage` (FR-I18N-02). Vezi acolo structura generală și de ce
 * pluralizarea NU poate refolosi regula cu două segmente a lui Laravel.
 *
 * TRADUCERE SCRISĂ DE ASISTENT, revizuită de proprietar (ADR-022, „Cost, stated
 * honestly") — pasajele marcate mai jos cu „NATIV:" sunt cele cu riscul cel mai mare de
 * ton/naturalețe greșit(ă) și cer prioritate la revizie: corespondența e cel mai vizibil
 * text din produs pentru un client real, spre deosebire de o etichetă scurtă de UI.
 *
 * Decizii de traducere notate aici, ca să nu pară scăpări:
 *   - „Owner"/„Manager" din `membership_records_need_new_owner.footer` NU mai sunt scrise
 *     literal aici. Nota anterioară le lăsa netraduse pentru că lipsea o sursă unică —
 *     `lang/{locale}/roles.php` a fost adăugat între timp (Valul 3, decizie a
 *     proprietarului din 2026-09-21), iar linia primește acum numele prin înlocuitorii
 *     `:owner`/`:manager`, rezolvați din acel fișier în
 *     `MembershipRecordsNeedNewOwnerNotification`. Riscul de „citat dezacordat" pe care
 *     nota îl invoca (Capcana 1, Val 4) dispare tocmai fiindcă textul nu mai e o copie.
 *   - Fraza e la SINGULAR („toute personne ayant le rôle …"), nu la plural ca engleza:
 *     pluralul francez al rolului `agent` („Commercial" → „Commerciaux") e neregulat, iar
 *     o frază construită pe forma de singular rămâne corectă indiferent ce nume primesc
 *     rolurile la revizia de traducere.
 *   - `membership_records_need_new_owner.action` NU citează literal eticheta de navigare
 *     „Unassigned" (care aparține cataloagelor `resources/js/locales/`, Val 3, nefăcut
 *     încă) — descrie acțiunea, nu pretinde să reproducă exact un buton care ar putea fi
 *     tradus diferit mai târziu. Aceeași precauție ca la rolurile de mai sus.
 */

return [

    'report_delivery' => [
        'subject' => 'Votre rapport est prêt : :report',
        'greeting' => 'Bonjour,',
        // NATIV: formulare standard, dar verificați ghilimelele franceze « » față de
        // simplele " " — păstrate " " deliberat aici (numele raportului e conținut
        // introdus de utilizator, FR-I18N-06, nu se retraduce/reformatează).
        'body' => 'Votre rapport « :report » a été généré (:rows, :format).',
        'rows' => '[0,1] :count ligne|[2,*] :count lignes',
        'attached' => 'Le fichier est joint à cet e-mail.',
        'signature' => '— Throughput',
    ],

    'export_ready' => [
        'greeting' => 'Bonjour :name,',
        'body' => 'L’export de données demandé pour :workspace est prêt à être téléchargé.',
        'download' => 'Télécharger l’archive',
        // NATIV: phrase longue, registre "légal/contractuel" — la plus exposée à un ton
        // maladroit si un francophone natif ne la relit pas.
        'retention' => 'Le lien reste valable :days et cessera de fonctionner le :expires ; passé ce délai, le fichier est supprimé. La demande elle-même reste visible dans l’historique des exports — il en restera donc toujours une trace — et vous pouvez demander un nouvel export à tout moment.',
        'days' => '[0,1] :count jour|[2,*] :count jours',
        'contents' => 'L’archive contient un fichier JSON par entité, un CSV à côté chaque fois que la table est plate, ainsi qu’un manifeste décrivant ce qu’elle contient et ce qu’elle ne contient pas.',
        'signature' => '— Throughput',
    ],

    'data_export_ready' => [
        'subject' => 'Votre export de données pour :workspace est prêt',
    ],

    // NATIV: formule d'ouverture commerciale — ton à valider (une entreprise B2B
    // francophone attend souvent un registre plus formel que l'anglais d'origine).
    // Corps complet depuis la seconde passe du Val 5 — voir `lang/en/mail.php` pour le
    // contexte de la panne précédente (seuls le sujet et `accept_cta` passaient par le
    // catalogue).
    'member_invitation' => [
        'subject' => ':inviter vous a invité à rejoindre :workspace sur Throughput',
        'greeting' => 'Bonjour,',
        // DE VERIFICAT — accord de genre : « invité(e) » laissé neutre, comme
        // `membership_records_need_new_owner.deactivated` ci-dessus (le sujet réel,
        // :inviter, peut être un homme ou une femme).
        'body' => 'Vous avez été invité(e) par :inviter à rejoindre :workspace sur Throughput en tant que :role.',
        'accept_cta' => 'Accepter l’invitation',
        'expiry' => 'Ce lien est valable :days. S’il expire, demandez à :inviter de vous en envoyer un nouveau.',
        'days' => '[0,1] :count jour|[2,*] :count jours',
        'unsolicited' => 'Si vous ne vous attendiez pas à cette invitation, vous pouvez ignorer cet e-mail — il ne se passe rien tant que vous n’avez pas accepté.',
        'signature' => '— Throughput',
    ],

    'dunning_payment_failed' => [
        'subject' => 'Échec du paiement pour votre abonnement :tenant (tentative :attempt)',
    ],

    'subscription_canceled' => [
        'subject' => 'Abonnement :tenant annulé',
    ],

    'subscription_unpaid' => [
        'subject' => 'Action requise : l’abonnement :tenant est impayé',
    ],

    'membership_records_need_new_owner' => [
        'subject' => '[0,1] :count enregistrement a besoin d’un nouveau propriétaire|[2,*] :count enregistrements ont besoin d’un nouveau propriétaire',
        'greeting' => 'Bonjour :name,',
        // NATIV: phrase la plus longue et la plus « métier » du lot — celle qui bénéficie
        // le plus d'une relecture humaine.
        'deactivated' => ':member a été désactivé(e) dans :tenant et a choisi de ne pas réattribuer immédiatement ses dossiers ouverts.',
        'deals' => '[0,1] :count affaire ouverte|[2,*] :count affaires ouvertes',
        'orders' => '[0,1] :count commande active|[2,*] :count commandes actives',
        // NATIV: accord de genre volontairement évité ("désormais sans propriétaire" est
        // invariable) — :deals ("affaire(s)", féminin) et :orders ("commande(s)",
        // également féminin) coïncident ici, mais la formulation reste indépendante du
        // genre pour ne rien casser si l'une des deux phrases change de forme.
        'unassigned' => ':deals et :orders sont désormais sans propriétaire.',
        'action' => 'Consulter les dossiers non attribués',
        'footer' => 'Rien n’a été perdu — ces dossiers restent visibles par toute personne ayant le rôle :owner ou :manager, jusqu’à ce que quelqu’un les réattribue.',
    ],

];
