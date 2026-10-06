<?php

/**
 * Jurnalul de activitate (§17, FR-AUD-*) și cele două fluxuri care îl citesc — feed-ul
 * dashboard-ului (`ActivityEntryResource`, FR-DEMO-01) și cronologia unui cont
 * (`App\Support\Accounts\AccountActivityTimeline`, FR-CRM-04) — ADR-022, specs.md §15.8
 * FR-I18N-04, plan „Lot I18N", goluri rămase după Val 3. Sursa de adevăr e ACEST fișier:
 * `php artisan i18n:coverage` compară simetric cu `lang/fr/activity.php`.
 *
 * PATRU registre, deliberat NEUNIFICATE — două ecrane diferite au nevoi diferite din
 * ACELAȘI `action`:
 *
 *  - `actions.*` — eticheta SCURTĂ a enum-ului coloanei (`activity_log.action`), folosită de
 *    `App\Support\Activity\ActivityActionLabel::resolve()`, singurul apelant (jurnalul
 *    tenant-ului, calea de scăpare a feed-ului, rândurile brute ale cronologiei unui cont).
 *  - `subjects.*` — numele entităților auditabile, aliniat la `App\Support\Activity\
 *    AuditableResources::map()` (`account`, `contact`, `deal`, `product`, `variant`,
 *    `order`, `invoice`), plus `membership` (scris manual în jurnal, fără tab de „History",
 *    §17.1 docblock `AuditableResources`) și fallback-ul `record` când `auditable_type` e
 *    `null` (export/import în masă, fără o entitate unică).
 *  - `entries.*` — frazele COMPUSE ale AMBELOR ecrane de jurnal (feed-ul dashboard-ului și
 *    pagina Activity Log), prin `App\Support\Activity\ActivityNarrative`, interpolate cu
 *    `:subject` din registrul de mai sus.
 *  - `timeline.*` — frazele cronologiei unui cont (`AccountActivityTimeline`): subiectul
 *    e FIX în fiecare cheie (o afacere, o comandă), nu interpolat din `subjects.*` — cele
 *    patru surse ale clasei nu au toate un `auditable_type` de mapat.
 *
 * FR-I18N-06 — granița: conținutul SCRIS DE UTILIZATOR (`$deal->title`, `$event->
 * toStage->name`, `$order->order_number`) NU intră niciodată aici — intră ca înlocuitor
 * (`:title`, `:stage`, `:label`) într-o frază-cadru tradusă. Fallback-urile GENERATE DE
 * APLICAȚIE atunci când acel conținut lipsește (`fallback_deal`, `fallback_stage`) SE
 * traduc — sunt text al aplicației, nu conținut de utilizator.
 */
return [

    'actions' => [
        'created' => 'Created',
        'updated' => 'Updated',
        'deleted' => 'Deleted',
        'login' => 'Login',
        'login_failed' => 'Login Failed',
        'exported' => 'Exported',
        'imported' => 'Imported',
        'bulk_action' => 'Bulk Action',
        'role_changed' => 'Role Changed',
    ],

    'subjects' => [
        'account' => 'Account',
        'contact' => 'Contact',
        'deal' => 'Deal',
        'product' => 'Product',
        'variant' => 'Variant',
        'order' => 'Order',
        'invoice' => 'Invoice',
        // `Membership` nu are alias în `AuditableResources::map()` (nu are tab de
        // „History" propriu) — scris manual aici, la fel cum e scris manual în jurnal.
        'membership' => 'Membership',
        // Litere mici, deliberat — identic cu literalul dinaintea acestui catalog
        // (`ActivityNarrative::subjectLabel()`, fallback-ul lui `$subject`).
        'record' => 'record',
    ],

    'entries' => [
        'created' => 'Created :subject',
        'updated' => 'Updated :subject',
        'deleted' => 'Deleted :subject',
        'login' => 'Logged in',
        'login_failed' => 'Failed login attempt',
        'exported' => 'Exported :subject',
        'imported' => 'Imported :subject',
        'bulk_action' => 'Performed a bulk action on :subject',
        'role_changed' => 'Changed a member role',

        // US-TEN-03 — caz special înaintea switch-ului de acțiuni: `MembersController::
        // applyDeactivation()` scrie `action = 'updated'` pe un `Membership` (enum-ul
        // Postgres al coloanei n-are o valoare dedicată), deci fraza generică „Updated
        // Membership" ar fi corectă, dar opacă pentru cine citește feed-ul.
        // Tipuri DERIVATE (`App\Support\Activity\ActivityKind`): în baza de date sunt
        // toate `updated`, fiindcă enum-ul coloanei e închis. Fraza proprie e singurul mod
        // în care feed-ul poate spune ce s-a întâmplat de fapt. `:subject` rămâne TIPUL
        // tradus; numele propriu al înregistrării vine separat, ca `subjectName`.
        'stage_moved' => 'Moved :subject to another stage',
        'invoice_paid' => 'Marked :subject as paid',
        'invoice_sent' => 'Sent :subject to the customer',
        // Scrisă de un job, nu de un om — actorul iese „System" (vezi `system_actor`).
        'invoice_overdue' => 'Flagged :subject as overdue',
        'invoice_void' => 'Voided :subject',
        'order_shipped' => 'Shipped :subject',

        'member_deactivated' => 'Deactivated a member',

        // Actorul unei acțiuni de sistem (`user_id` null — job programat, webhook, §17.1).
        'system_actor' => 'System',
    ],

    'timeline' => [
        'deal_created' => 'Deal created: :title',
        'stage_moved' => ':title moved to :stage',
        'order_placed' => 'Order placed: :label',

        // Fallback-uri GENERATE DE APLICAȚIE, nu conținut de utilizator (vezi FR-I18N-06
        // în docblock-ul de mai sus) — `$event->deal` sau `$event->toStage` lipsesc
        // (relația a fost ștearsă între citire și scriere).
        'fallback_deal' => 'Deal',
        'fallback_stage' => 'a new stage',
    ],

];
