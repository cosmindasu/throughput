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
 *     §6.4) — corpul e randat prin catalogul ăsta pentru TOATE CELE ȘASE vederi Blade:
 *     `reports/mail/delivery`, `gdpr/mail/export-ready`, `members/mail/invitation`
 *     (completate la Valul 5 al Lotului I18N), plus
 *     `billing/mail/{canceled,unpaid,payment-failed}` (I18N-02 — golul semnalat, dar
 *     neatins, la Valul 5: subiectul trecea deja prin `trans()`, corpul rămăsese 100%
 *     literal, în engleză, deși `subscription_canceled`/`subscription_unpaid`/
 *     `dunning_payment_failed` existau ca grupuri doar pentru `subject`);
 *   - conținutul integral al `App\Notifications\MembershipRecordsNeedNewOwnerNotification`
 *     (BR-TEN-06) — singura notificare din `app/Notifications/`.
 *
 * Pluralizare (FR-I18N-05, plan „Lot I18N" Val 2 — „Str::plural() → trans_choice()"):
 * franceza tratează 0 ca SINGULAR, engleza îl tratează ca PLURAL — de asta rândurile de
 * mai jos cu chei `rows`/`days`/`deals`/`orders`/`subject` folosesc condiții explicite
 * `{0}`/`[0,1]` pe fiecare limbă, NICIODATĂ regula implicită cu doar două segmente
 * (`:count apples|:count apple`), care ar presupune aceeași regulă pentru ambele limbi.
 *
 * Regula are o formă verificabilă mecanic, folosită ca să se confirme că e singurul caz:
 * un șir care are MĂCAR o condiție explicită, dar lasă un `count` să cadă pe calea de
 * rezervă a lui `MessageSelector`, e un defect — `Illuminate\Translation\MessageSelector::
 * extract()` întoarce `null` acolo, iar ramura de rezervă nu aplică `trim()`. Trecute
 * prin filtrul ăsta, toate cheile din `lang/{en,fr}/*.php` ies curate; `subject` era
 * singura excepție (vezi nota de la ea).
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

    // App\Mail\MemberInvitationMail (US-TEN-01, §6.4) — subiect + corpul întreg
    // (`members/mail/invitation.blade.php`), completat la a doua trecere a Valului 5.
    // Prima trecere mutase doar `accept_cta` și lăsase restul literal, în engleză, cu o
    // notă care presupunea greșit că „restul emailului trece deja prin catalog"; nu era
    // adevărat, iar analiza a semnalat golul în loc să-l repare. Structură în oglindă cu
    // `report_delivery` mai sus: `body` conține numele de invitator/workspace/rol,
    // conținut introdus de UTILIZATOR, deci vederea le compune manual cu `<strong>`+`e()`
    // înainte de interpolare și randează rezultatul RAW (`{!! !!}`) — exact tehnica de
    // acolo. Destinatarul e o adresă fără cont încă (invitație) — FR-I18N-05 cere limba
    // sesiunii care a trimis invitația, ca aproximare rezonabilă, cu fallback `en`; firul
    // de apel real (`App\Actions\Members\InviteMemberAction`) nu e în perimetrul acestui
    // lot — vezi raportul de livrare.
    'member_invitation' => [
        'subject' => ':inviter invited you to :workspace on Throughput',
        'greeting' => 'Hi,',
        'body' => ':inviter invited you to join :workspace on Throughput as :role.',
        'accept_cta' => 'Accept the invitation',
        'expiry' => 'This link is valid for :days. If it expires, ask :inviter to send a new one.',
        'days' => '{0} :count days|{1} :count day|[2,*] :count days',
        'unsolicited' => "If you weren't expecting this invitation, you can ignore this email — nothing happens until you accept.",
        'signature' => '— Throughput',
    ],

    // App\Mail\DunningPaymentFailedMail (FR-BILL-04, §12.2) — subiect + corp
    // (`billing/mail/payment-failed.blade.php`, I18N-02). Destinatarii sunt Owner-ii
    // ACTIVI ai tenantului (conturi reale, `users.locale`) — firul de apel real
    // (`App\Listeners\Billing\SendPaymentFailedDunningEmail`) e cablat per destinatar din
    // Lot I18N Val 5 (`Mail::to($owner)`, un `Mailable` per Owner, fiecare în limba lui).
    // `body` conține numele de tenant, conținut introdus de UTILIZATOR, deci vederea îl
    // compune manual cu `<strong>`+`e()` înainte de interpolare și randează rezultatul RAW
    // (`{!! !!}`) — exact tehnica din `report_delivery`/`member_invitation` mai sus.
    'dunning_payment_failed' => [
        'subject' => 'Payment failed for your :tenant subscription (attempt :attempt)',
        'greeting' => 'Hi,',
        'body' => 'A payment attempt for the :tenant subscription failed (attempt :attempt). Stripe will keep retrying automatically — your workspace still has full access while this happens.',
        'billing_cta' => 'To avoid any interruption, update the payment method from the billing page:',
        'signature' => '— Throughput',
    ],

    // App\Mail\SubscriptionCanceledMail (§12.2/§20.5) — subiect + corp
    // (`billing/mail/canceled.blade.php`, I18N-02), aceeași notă ca mai sus
    // (`App\Listeners\Billing\SendSubscriptionCanceledEmail`, cablat din Lot I18N Val 5).
    'subscription_canceled' => [
        'subject' => ':tenant subscription canceled',
        'greeting' => 'Hi,',
        'body' => 'The :tenant subscription was canceled. The workspace is now locked for everyone except the billing page — data stays intact and exportable for 30 days, and you can reactivate at any time during that window without redoing setup.',
        'billing_cta' => 'Reactivate from the billing page:',
        'signature' => '— Throughput',
    ],

    // App\Mail\SubscriptionUnpaidMail (§12.2) — subiect + corp
    // (`billing/mail/unpaid.blade.php`, I18N-02), aceeași notă
    // (`App\Listeners\Billing\SendSubscriptionUnpaidEmail`, cablat din Lot I18N Val 5).
    // „unpaid" în `body` e STATIC (starea Stripe a abonamentului, nu conținut de
    // utilizator), deci rămâne literal `<strong>` în catalog — spre deosebire de `:tenant`,
    // care poartă `<strong>` din vedere, cu `e()`, ca la `dunning_payment_failed` de mai sus.
    'subscription_unpaid' => [
        'subject' => 'Action needed: :tenant subscription is unpaid',
        'greeting' => 'Hi,',
        'body' => 'Stripe has exhausted its automatic retries for the :tenant subscription, and it is now marked <strong>unpaid</strong>. Everyone in the workspace can still view and export data, but creating, editing or deleting anything is blocked until the payment method is fixed — including for you, the Owner.',
        'billing_cta' => 'Update the payment method from the billing page:',
        'signature' => '— Throughput',
    ],

    // App\Notifications\MembershipRecordsNeedNewOwnerNotification (BR-TEN-06,
    // US-TEN-03) — destinatarii sunt Owner-ii activi ai tenantului, notificați prin
    // `Illuminate\Support\Facades\Notification::send()`; limba se rezolvă automat, PER
    // destinatar, din `User::preferredLocale()` (contractul `HasLocalePreference`,
    // ADR-022) — niciun `->locale()` explicit necesar aici, spre deosebire de
    // `Mail::to()`, care nu rezolvă automat decât pe un SINGUR model, nu pe o listă.
    'membership_records_need_new_owner' => [
        // Ramura `{0}` nu e decorativă. Fără ea, `trans_choice(..., 0)` nu potrivește
        // nicio condiție scrisă și cade pe calea de rezervă a lui `MessageSelector`
        // (`stripConditions()` + indexul gramatical), care NU aplică `trim()` — ieșea
        // literal „ 0 records need a new owner", cu spațiul de după `}`. Latent azi:
        // singurul loc care trimite notificarea (`MembersController::applyDeactivation()`)
        // e păzit de `if ($counts['total'] > 0)`. Reparat totuși, fiindcă `deals`/`orders`
        // de mai jos au deja ramura și pentru ele 0 chiar se întâmplă (unul din cei doi
        // numărători poate fi zero când celălalt nu e) — o singură cheie care se abate de
        // la convenția fișierului e exact cea pe care o rescrie greșit cine adaugă a treia
        // limbă. Franceza n-are nevoie de perechea asta: `[0,1]` acoperă deja zero, care
        // acolo e singular.
        'subject' => '{0} :count records need a new owner|{1} :count record needs a new owner|[2,*] :count records need a new owner',
        'greeting' => 'Hi :name,',
        'deactivated' => ':member was deactivated in :tenant, and chose not to reassign their open records right away.',
        'deals' => '{0} :count open deals|{1} :count open deal|[2,*] :count open deals',
        'orders' => '{0} :count active orders|{1} :count active order|[2,*] :count active orders',
        'unassigned' => ':deals and :orders are now unassigned.',
        'action' => 'Review in Unassigned',
        'footer' => 'Nothing was lost — these records are visible to every :owner and :manager until someone reassigns them.',
    ],

];
