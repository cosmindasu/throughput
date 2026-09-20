<?php

/**
 * Catalog EN pentru corespondență (mail + notificări) — ADR-022, specs.md §15.8
 * FR-I18N-04/05. SINGURUL fișier din `lang/` administrat de acest lot: restul
 * cataloagelor backend (flash, validare, PDF de factură/export, rapoarte built-in) sunt
 * ale altor agenți/valuri — nu se adaugă alte namespace-uri aici.
 *
 * `en` e sursa de adevăr pentru `php artisan i18n:coverage` (FR-I18N-02): orice cheie de
 * aici trebuie să aibă pereche EXACTĂ (aceeași cale „dot") în `lang/fr/mail.php`, altfel
 * comanda pică, blocant în CI.
 *
 * Cheile de mai jos acoperă:
 *   - subiectul FIECĂRUIA din cele 6 `Mailable`-uri din `app/Mail/` (§16.2, §12.2, §20.5,
 *     §6.4) — corpul e randat prin catalogul ăsta doar pentru cele DOUĂ vederi Blade
 *     deținute de acest lot (`reports/mail/delivery`, `gdpr/mail/export-ready`); corpul
 *     celorlalte patru (`members/mail/invitation`, `billing/mail/*`) aparține altor
 *     agenți/valuri, neatins aici;
 *   - conținutul integral al `App\Notifications\MembershipRecordsNeedNewOwnerNotification`
 *     (BR-TEN-06) — singura notificare din `app/Notifications/`.
 *
 * Pluralizare (FR-I18N-05, plan „Lot I18N" Val 2 — „Str::plural() → trans_choice()"):
 * franceza tratează 0 ca SINGULAR, engleza îl tratează ca PLURAL — de asta rândurile de
 * mai jos cu chei `rows`/`days`/`deals`/`orders` folosesc condiții explicite `{0}`/`[0,1]`
 * pe fiecare limbă, NICIODATĂ regula implicită cu doar două segmente (`:count
 * apples|:count apple`), care ar presupune aceeași regulă pentru ambele limbi.
 */

return [

    // App\Mail\ReportDeliveryMail + resources/views/reports/mail/delivery.blade.php
    // (specs.md §16.2 pct. 4). Randat în limba creatorului raportului
    // (`report_definitions.created_by`), FR-I18N-05 — destinatarii sunt adrese arbitrare,
    // fără cont, deci nu au propriul `users.locale` (vezi App\Jobs\Reports\DeliverReportJob).
    'report_delivery' => [
        'subject' => 'Your report is ready: :report',
        'greeting' => 'Hi,',
        'body' => 'Your report ":report" has been generated (:rows, :format).',
        'rows' => '{0} :count rows|{1} :count row|[2,*] :count rows',
        'attached' => 'The file is attached to this email.',
        'signature' => '— Throughput',
    ],

    // App\Mail\DataExportReadyMail + resources/views/gdpr/mail/export-ready.blade.php
    // (FR-GDPR-01, specs.md §20.5). Randat în limba destinatarului real — Owner-ul
    // autentificat care a CERUT exportul (`data_export_requests.requested_by`, un cont
    // real, cu `users.locale`) — spre deosebire de rapoarte, aici FR-I18N-05 nu are nevoie
    // de aproximare: destinatarul chiar există ca utilizator.
    'export_ready' => [
        'greeting' => 'Hi :name,',
        'body' => 'The data export you requested for :workspace is ready to download.',
        'download' => 'Download the archive',
        'retention' => 'The link works for :days and stops working on :expires, after which the file is deleted. The request itself stays in the export history, so there is always a record that it was made — you can request a new export at any time.',
        'days' => '{0} :count days|{1} :count day|[2,*] :count days',
        'contents' => 'The archive holds one JSON file per entity, a CSV alongside it wherever the table is flat, and a manifest describing what is inside and what is not.',
        'signature' => '— Throughput',
    ],

    // Subiect DOAR — corpul e `gdpr.mail.export-ready`, cheile de mai sus.
    'data_export_ready' => [
        'subject' => 'Your data export for :workspace is ready',
    ],

    // App\Mail\MemberInvitationMail (US-TEN-01, §6.4) — subiect DOAR; corpul
    // (`members/mail/invitation.blade.php`) nu e al acestui lot. Destinatarul e o adresă
    // fără cont încă (invitație) — FR-I18N-05 cere limba sesiunii care a trimis invitația,
    // ca aproximare rezonabilă, cu fallback `en`; firul de apel real
    // (`App\Actions\Members\InviteMemberAction`) nu e în perimetrul acestui lot — vezi
    // raportul de livrare.
    'member_invitation' => [
        'subject' => ':inviter invited you to :workspace on Throughput',
    ],

    // App\Mail\DunningPaymentFailedMail (FR-BILL-04, §12.2) — subiect DOAR; corpul
    // (`billing/mail/payment-failed.blade.php`) nu e al acestui lot. Destinatarii sunt
    // Owner-ii ACTIVI ai tenantului (conturi reale, `users.locale`) — firul de apel real
    // (`App\Listeners\Billing\SendPaymentFailedDunningEmail`) nu e în perimetrul acestui
    // lot — vezi raportul de livrare.
    'dunning_payment_failed' => [
        'subject' => 'Payment failed for your :tenant subscription (attempt :attempt)',
    ],

    // App\Mail\SubscriptionCanceledMail (§12.2/§20.5) — subiect DOAR, aceeași notă ca mai
    // sus (`App\Listeners\Billing\SendSubscriptionCanceledEmail`, nu e al acestui lot).
    'subscription_canceled' => [
        'subject' => ':tenant subscription canceled',
    ],

    // App\Mail\SubscriptionUnpaidMail (§12.2) — subiect DOAR, aceeași notă
    // (`App\Listeners\Billing\SendSubscriptionUnpaidEmail`, nu e al acestui lot).
    'subscription_unpaid' => [
        'subject' => 'Action needed: :tenant subscription is unpaid',
    ],

    // App\Notifications\MembershipRecordsNeedNewOwnerNotification (BR-TEN-06,
    // US-TEN-03) — destinatarii sunt Owner-ii activi ai tenantului, notificați prin
    // `Illuminate\Support\Facades\Notification::send()`; limba se rezolvă automat, PER
    // destinatar, din `User::preferredLocale()` (contractul `HasLocalePreference`,
    // ADR-022) — niciun `->locale()` explicit necesar aici, spre deosebire de
    // `Mail::to()`, care nu rezolvă automat decât pe un SINGUR model, nu pe o listă.
    'membership_records_need_new_owner' => [
        'subject' => '{1} :count record needs a new owner|[2,*] :count records need a new owner',
        'greeting' => 'Hi :name,',
        'deactivated' => ':member was deactivated in :tenant, and chose not to reassign their open records right away.',
        'deals' => '{0} :count open deals|{1} :count open deal|[2,*] :count open deals',
        'orders' => '{0} :count active orders|{1} :count active order|[2,*] :count active orders',
        'unassigned' => ':deals and :orders are now unassigned.',
        'action' => 'Review in Unassigned',
        'footer' => 'Nothing was lost — these records are visible to every Owner and Manager until someone reassigns them.',
    ],

];
