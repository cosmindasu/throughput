<?php

/**
 * Mesaje flash (succes/eroare/notificare) — ADR-022, specs.md §15.8 FR-I18N-04, plan
 * „Lot I18N" Val 2. Sursa de adevăr e ACEST fișier (`en`): `php artisan i18n:coverage`
 * compară simetric cheile de aici cu `lang/fr/flash.php` și pică dacă una lipsește pe
 * oricare parte.
 *
 * Chei structurate PE MODUL, în oglindă cu directoarele din `app/Http/Controllers/Web/`
 * (accounts, contacts, deals, orders, stock, products, invoices, payments, imports,
 * reports, stages, bulk, members, invitations, api_tokens, carrier_settings, data_export,
 * exports, subscription, demo) — un fișier plat cu 84+ chei la același nivel ar fi
 * nenavigabil.
 *
 * Pluralizare: cheile care variază după o numărătoare (deals/orders/records/rânduri)
 * folosesc forma `trans_choice()` cu DOUĂ segmente separate prin `|` (singular|plural).
 * Motivul nu e stilistic: `Illuminate\Translation\MessageSelector::getPluralIndex()`
 * tratează `fr` DIFERIT de `en` — pentru `en`, doar `count == 1` cade pe segmentul
 * singular (0 e plural); pentru `fr`, ATÂT 0 CÂT ȘI 1 cad pe segmentul singular (vezi
 * sursa vendor, citată în raportul lotului). Un `Str::plural()` sau un ternar pe
 * `=== 1` scris manual n-ar fi respectat asta — motivul pentru care mesajele compuse din
 * `app/Models/{Account,Product,Variant,Stage}.php` (deletionBlockedReason) au fost
 * rescrise să treacă prin `trans_choice()` în loc de `Str::plural()`.
 *
 * Interpolare: valorile variabile (nume, email, numărători) intră prin parametri de
 * traducere (`:name`, `:email`, `:count`), NICIODATĂ prin concatenare — altfel franceza
 * n-ar putea reordona cuvintele în jurul lor.
 */
return [

    'accounts' => [
        'created' => 'Account created.',
        'updated' => 'Account updated.',
        'deleted' => 'Account deleted.',

        // `App\Models\Account::deletionBlockedReason()` — compune fraza din 1-2 „clauze"
        // (deals/orders), joncțiune cu `flash.common.list_and`, ca franceza să poată
        // reordona fiecare clauză și joncțiunea fără să atingă acest șablon.
        'deletion_blocked' => 'This account cannot be deleted: it has :parts.',
        'deletion_blocked_deals_clause' => ':count deal|:count deals',
        // Notă separată, cu propria numărătoare (`:deleted`, un SUBSET al `:count` de mai
        // sus) — nu combinată în același `trans_choice`, ca acordul de gen/număr francez
        // pe „supprimée(s)" să depindă de subset, nu de total (5 deals, din care 1 șters,
        // ar fi acordat greșit dacă ar depinde de „5"). Invariantă în engleză (deci un
        // singur segment, fără „|"), pluralizabilă în franceză — `trans_choice()` acceptă
        // amândouă formele, vezi docblock-ul de sus.
        'deletion_blocked_deleted_note' => '(:deleted deleted, kept for pipeline history)',
        'deletion_blocked_orders_clause' => ':count order|:count orders',
    ],

    'contacts' => [
        'created' => 'Contact created.',
        'updated' => 'Contact updated.',
        'deleted' => 'Contact deleted.',
        // FR-GDPR/BR-CRM-01 — contactul e referit de deals/orders, deci anonimizat în loc
        // de șters fizic (`ContactErasure`).
        'anonymized' => 'Contact anonymized — it is referenced by deals or orders, so its personal data was removed and the record kept.',
    ],

    'deals' => [
        'created' => 'Deal created.',
        'updated' => 'Deal updated.',
        'deleted' => 'Deal deleted.',
    ],

    'orders' => [
        'created' => 'Order created.',
        'updated' => 'Order updated.',
        'deleted' => 'Order deleted.',
        'confirmed' => 'Order confirmed.',
        'cancelled' => 'Order cancelled.',

        'shipments' => [
            'created' => 'Shipment created — its label is being generated.',
            'discarded' => 'Shipment discarded.',
            'marked_shipped' => 'Shipment marked as shipped.',
            'retrying_label' => 'Retrying the shipping label.',
        ],
    ],

    'stock' => [
        'received' => 'Stock received.',
        'adjusted' => 'Stock adjusted.',
        'transferred' => 'Stock transferred.',
    ],

    'products' => [
        'created' => 'Product created.',
        'updated' => 'Product updated.',
        'deleted' => 'Product deleted.',

        // `App\Models\Product::deletionBlockedReason()` — aceeași tehnică de compunere ca
        // la `accounts.deletion_blocked`.
        'deletion_blocked' => 'This product cannot be deleted: it has :parts.',
        'deletion_blocked_stock_history_clause' => ':count variant with stock history|:count variants with stock history',
        'deletion_blocked_used_on_orders_clause' => ':count variant used on orders|:count variants used on orders',

        'variants' => [
            'created' => 'Variant created.',
            'updated' => 'Variant updated.',
            'deleted' => 'Variant deleted.',

            // `App\Models\Variant::deletionBlockedReason()` — aici NU sunt compuse (o
            // singură condiție blochează la un moment dat), deci fraze fixe, fără `:parts`.
            'deletion_blocked_stock_movements' => 'This variant cannot be deleted: it has recorded stock movements.',
            'deletion_blocked_order_lines' => 'This variant cannot be deleted: it is used on at least one order.',
        ],
    ],

    'invoices' => [
        'created' => 'Invoice created.',
        'marked_sent' => 'Invoice marked as sent.',
        'voided' => 'Invoice voided.',
        'pdf_not_ready' => 'This invoice PDF is not ready yet.',
        'regenerating_pdf' => 'Generating the PDF again.',
    ],

    'payments' => [
        'recorded' => 'Payment recorded.',
    ],

    'imports' => [
        'uploaded' => 'File uploaded — map the columns to continue.',
        'mapping_saved' => 'Mapping saved.',
        'validating' => 'Validating in the background — this page updates automatically.',
        'importing' => 'Importing valid rows in the background — this page updates automatically.',
        'cancelled' => 'Import cancelled.',
    ],

    'reports' => [
        'created' => 'Report created.',
        'updated' => 'Report updated.',
        'deleted' => 'Report deleted.',
        'queued' => 'Report queued — this page will update automatically.',
    ],

    'stages' => [
        'created' => 'Stage added.',
        'updated' => 'Stage updated.',
        'deleted' => 'Stage deleted.',
        'reordered' => 'Stage order updated.',

        // `App\Models\Stage::deletionBlockedReason()` — DOUĂ fraze fixe distincte (nu
        // compuse ca la accounts/products): un deal prezent pe etapă BLOCHEAZĂ diferit
        // de un istoric de etapă fără deal activ, deci nu se combină niciodată în aceeași
        // propoziție.
        'deletion_blocked_deals_present' => 'This stage has :count deal on it. Move it to another stage before deleting it.|This stage has :count deals on it. Move them to another stage before deleting it.',
        'deletion_blocked_deal_history' => "Deals have passed through this stage before, and their stage history still points at it, so it can't be deleted. You can rename it instead.",

        // `StageController::destroy()` — plasa de siguranță pe `QueryException` 23503
        // (cursa creare-deal/ștergere-etapă), text distinct de cel de mai sus.
        'deletion_blocked_fk' => 'This stage is still used by deals or their stage history and cannot be deleted.',
    ],

    'bulk' => [
        'reassign_owner_started' => 'Bulk operation started — this page updates automatically.',
        'cancel_draft_orders_started' => 'Cancelling draft orders — this page updates automatically.',
        'update_price_started' => 'Updating prices — this page updates automatically.',
        'set_active_started' => 'Updating product status — this page updates automatically.',

        'operation_missing' => 'This operation no longer exists.',
        'cancelling_in_progress' => 'Cancelling — rows already in progress will finish, the rest stop.',
        'cancelled' => 'Cancelled.',
        'already_finished' => 'This operation has already finished.',

        // NU `concurrency_limit_reached` aici: `App\Support\Bulk\BulkConcurrencyGuard::
        // refusal()` (sursa acestui text pentru flash-ul din `ListExport`) a fost tradus
        // de un alt agent, în paralel, pe catalogul lui (`rules.bulk.concurrency_limit`)
        // — are și un al doilea apelant, o `ValidationException`, din domeniul acelui lot.
        // O cheie a doua, aici, ar fi dublat traducerea fără niciun apelant real.
        'groups' => [
            'cancelling' => 'Cancelling every operation in this group — rows already in progress will finish, the rest stop.',
        ],
    ],

    'unassigned' => [
        'reassigning' => 'Reassigning every unassigned record — this page updates automatically.',
    ],

    // `App\Support\SavedViews\SavedViewDefaultRedirect::resolve()` — găsit prin căutare
    // exhaustivă a mecanismului de flash: foloseste `session()->flash(...)`, NU
    // `->with(...)`, forma pe care s-a măsurat inițial cele „84 de apeluri". Un singur
    // asemenea caz în tot `app/` (verificat).
    'saved_views' => [
        'default_team_view_deleted' => 'The team view you used as default was deleted.',
    ],

    'members' => [
        // `:role` NU e tradus aici: numele rolului (Owner/Manager/Agent/Viewer) e etichetă
        // de domeniu, pe același rând cu `OrderStatus::label()` — semnalat explicit ca
        // fișier al altui lot (app/Enums, app/Support/Permissions), nu recalculat aici.
        'role_updated' => ':name is now :role in this workspace.',

        'deactivated' => ':name was deactivated.',
        // `MembersController::deactivate()` — capcana EXACTĂ semnalată în task: „0 records"
        // e plural în engleză, dar SINGULAR în franceză (vezi docblock-ul de la începutul
        // fișierului). `trans_choice()` peste `Str::plural()`/ternar manual, obligatoriu.
        'deactivated_with_open_records' => ':name was deactivated. :count record needs a new owner — see Unassigned.|:name was deactivated. :count records need a new owner — see Unassigned.',
        'deactivating_with_reassignment' => 'Deactivating :name and reassigning their open records — this page updates automatically.',

        // Fallback când `$membership->user` nu mai e încărcat (relația a fost ștearsă
        // între citire și scriere) — folosit de `updateRole()` ȘI `deactivate()`.
        'fallback_name' => 'This member',
    ],

    'invitations' => [
        'sent_new_user' => 'Invitation sent to :email. They have 7 days to accept it.',
        'sent_existing_user' => 'Invitation sent to :email. They already have a Throughput account, so accepting only takes a click.',
        'resent' => 'A new invitation link was sent to :email. The previous link no longer works.',
        'revoked' => 'The invitation to :email was revoked. Its link no longer works.',

        // Fallback-uri când `$membership->user` nu mai e încărcat pe relație (n-ar trebui
        // să se întâmple în practică, dar `?->email` întoarce `null` dacă da) — text care
        // intră tot în mesajul flash prin `:email`, deci tot al acestui lot.
        'email_fallback_invited' => 'the invited address',
        'email_fallback_generic' => 'that address',

        // `AcceptInvitationController::accept()` — mesajul de bun venit al invitatului,
        // distinct de fluxul de TRIMITERE a invitației de mai sus (alt controller, altă
        // persoană care vede mesajul).
        'accepted' => "You're in — welcome to :tenant.",
    ],

    'api_tokens' => [
        'created' => 'API token created. Copy it now — it is not shown again.',
        'already_revoked' => 'That token was already revoked.',
        'revoked' => 'API token revoked. Any integration using it stops working immediately.',
    ],

    'carrier_settings' => [
        'updated' => 'Carrier settings updated.',
    ],

    'data_export' => [
        'queued' => 'Your data export is queued. You will get an email when the archive is ready.',
        'file_unavailable' => 'That export file is no longer available. Request a new export.',
    ],

    'exports' => [
        'started' => 'Export started — this page will update automatically.',
        'demo_limit_exceeded' => 'This export exceeds the demo limit and cannot be started.',

        // `App\Support\Exports\ListExport::respond()` — `:format` e o valoare tehnică
        // (`pdf`/`zip`, `ExportFormat::value`), NU tradusă (identică cu parametrul de URL
        // `?format=`, pe care utilizatorul îl scrie/citește literal). Pluralizat pe
        // `:total` (numărul de rânduri al exportului), aceeași capcană „0/1" ca la
        // `members.deactivated_with_open_records`.
        'pdf_row_cap_exceeded' => 'This export has :total row; :format export is capped at :cap. Use CSV for larger exports.|This export has :total rows; :format export is capped at :cap. Use CSV for larger exports.',
    ],

    'subscription' => [
        // `EnsureSubscriptionAccess::respondReadOnly()` — starea `unpaid` (§12.2).
        'read_only' => 'Your subscription is unpaid — update your payment method to restore full access.',
    ],

    // `App\Support\DemoMode::GUARDED_ACTIONS` — array-ul rămâne o CONSTANTĂ de clasă
    // (PHP nu permite apeluri de funcție în inițializarea unui `const`), deci ține CHEIA
    // de traducere ca `message`, iar `DemoMode::refusal()` face `__($key)` la apelare, nu
    // la definirea array-ului. Cheile de mai jos sunt ținta acelor referințe.
    'demo' => [
        'workspace_delete_disabled' => 'Deleting a workspace is disabled in the public demo. The demo data resets every night at 03:00 UTC.',
        'members_deactivate_disabled' => 'Deactivating a member is disabled in the public demo — these are the shared logins other visitors use.',
        'members_change_role_disabled' => "Changing a member's role is disabled in the public demo — these are the shared logins other visitors use. (A workspace must always keep at least one active :owner.)",
        'members_remove_disabled' => 'Removing a member is disabled in the public demo — these are the shared logins other visitors use. (A workspace must always keep at least one active :owner.)',
        'api_tokens_revoke_all_disabled' => 'Revoking every API token at once is disabled in the public demo. Revoke tokens one by one instead.',
        'subscription_cancel_disabled' => 'Cancelling the subscription is disabled in the public demo. Billing runs in Stripe test mode here, so there is nothing real to cancel.',
    ],

    // Joncțiunea folosită la compunerea `accounts.deletion_blocked`/`products.deletion_blocked`
    // din 1-2 clauze traduse — externalizată, nu concatenată direct în cod, ca franceza
    // să nu fie forțată să repete " and " hardcodat.
    'common' => [
        'list_and' => 'and',
    ],

];
