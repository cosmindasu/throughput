<?php

/**
 * Mesaje de REGULĂ DE BUSINESS (stare, tranziții, praguri) — cele aruncate prin
 * `ValidationException::withMessages()` din `app/Actions/**` și câteva puncte de graniță
 * adiacente (vezi raportul lotului I18N, Val 2, ADR-022, specs.md §15.8 FR-I18N-04).
 *
 * NU e vorba de mesajele de FORMULAR („required", „email", „min") — acelea rămân pe
 * `lang/{locale}/validation.php`, deja localizabile din framework fără nicio schimbare
 * aici. Astea sunt reguli scrise manual, care depind de STAREA curentă a datelor
 * (tranziții, praguri, concurență), nu de forma câmpurilor trimise.
 *
 * Structurat pe domeniu (stoc, comenzi, deal-uri, pipeline, expedieri, facturi, curierat,
 * importuri, GDPR, operații în masă) — în oglindă exactă cu `lang/fr/rules.php`;
 * `php artisan i18n:coverage` verifică simetria cheie cu cheie (FR-I18N-02), blocant în CI.
 *
 * Interpolare prin PARAMETRI de traducere (`:status`, `:count`, ...), NICIODATĂ
 * concatenare manuală — franceza reordonează cuvintele în jurul valorii; o concatenare ar
 * îngheța ordinea engleză în mijlocul unei propoziții franceze.
 *
 * Pluralizare prin `trans_choice()` (sintaxă `singular|plural`), niciodată `if`/
 * `Str::plural()`: indexul de plural al lui Laravel e per-locale, nu doar „==1 sau nu" —
 * pentru `fr`, `MessageSelector::getPluralIndex()` alege deja forma SINGULAR și pentru 0,
 * și pentru 1 (`($number == 0 || $number == 1) ? 0 : 1`, cablat în framework, verificat
 * direct în `vendor/laravel/framework/.../MessageSelector.php`) — exact capcana centrală a
 * lotului, rezolvată gratuit de mecanismul nativ, nu de cod scris aici.
 *
 * Câteva chei de aici (`bulk.demo_row_cap`, `bulk.concurrency_limit`) au fost mutate din
 * `App\Support\DemoMode::bulkRowCapRefusal()` / `App\Support\Bulk\BulkConcurrencyGuard::refusal()`
 * — SINGURELE lor puncte de folosire erau apeluri `ValidationException::withMessages()`
 * (`bulkRowCapRefusal()` doar din `DispatchBulkOperationAction`), respectiv un apel de
 * validare PLUS un mesaj flash care citește ACELAȘI text (`BulkConcurrencyGuard::refusal()`,
 * folosit și din `App\Support\Exports\ListExport`) — un singur șir sursă, tradus o dată,
 * corect pentru amândouă. Vezi raportul lotului pentru motivul exact al fiecărei mutări.
 */
return [

    'stock' => [
        'negative_on_hand' => 'Only :count unit is on hand at this location; this change would take it below zero.|Only :count units are on hand at this location; this change would take it below zero.',
        'insufficient_at_source' => 'Only :count unit is on hand at the source location.|Only :count units are on hand at the source location.',
    ],

    'orders' => [
        'cannot_cancel' => "This order can't be cancelled from its current status (:status).",
        'has_shipment' => 'This order has a shipment and can no longer be cancelled.',
        'cannot_confirm' => "This order can't be confirmed from its current status (:status).",
        'account_required' => 'This order needs a valid account before it can be confirmed.',
        'lines_required' => 'Add at least one line before confirming this order.',
        'backorder_confirmation_required' => 'One or more lines exceed the available stock. Confirm explicitly to place this order as a backorder.',
        'cannot_transition' => "This order can't move to :target from its current status (:status).",
    ],

    'deals' => [
        'stage_wrong_pipeline' => "This stage does not belong to the deal's pipeline.",
        'already_on_stage' => 'This deal is already on this stage.',
        // Text EXACT cerut de criteriul de acceptanță Gherkin (specs.md §9.3) — FĂRĂ punct
        // final, mot-à-mot. `MoveDealStageActionTest`/`DealStageHttpTest` îl compară literal
        // în locale `en` (implicit în teste) — nu „corecta" punctuația aici.
        'value_required_for_won' => 'Set a deal value before marking as Won',
        'lost_reason_required' => 'Select a reason before marking this deal as Lost.',
    ],

    'pipeline' => [
        'duplicate_stage_order' => 'The stage order cannot repeat the same stage twice.',
        'stage_order_mismatch' => 'The stage order must list every stage of this pipeline, exactly once, and no others.',
        'both_won_and_lost' => 'A stage cannot be marked as both Won and Lost.',
        'name_taken' => 'A stage named ":name" already exists in this pipeline.',
        'flag_taken' => 'This pipeline already has a stage marked as :label.',
        // Folosit de `flag_taken` de mai sus — NU e un enum PHP (doar `is_won`/`is_lost`
        // booleene pe `stages`), deci stă aici, nu în `enums.php` (rezervat lui
        // `OrderStatus`, singurul enum cerut explicit de brief-ul lotului).
        'flags' => [
            'won' => 'Won',
            'lost' => 'Lost',
        ],
    ],

    'shipments' => [
        'invalid_status_for_creation' => 'Shipments can only be created from a confirmed or partially fulfilled order (currently :status).',
        'choose_quantity' => 'Choose a quantity greater than zero on at least one line.',
        'line_not_in_order' => 'One of the selected lines no longer belongs to this order.',
        'remaining_to_ship' => 'Only :count unit is left to ship on this line.|Only :count units are left to ship on this line.',
        'discard_requires_label_failed' => 'Only a shipment whose label failed can be discarded (currently :status).',
        'mark_shipped_requires_label_purchased' => 'Only a shipment with a purchased label can be marked as shipped (currently :status).',
        'no_lines' => 'This shipment has no lines.',
        // `:available`/`:requested` NU trec prin `trans_choice()` — două numere independente
        // în același mesaj, fiecare cu propria pluralizare, ar cere două chei separate
        // combinate manual; niciun test nu cere asta, iar fraza rămâne corectă și fără plural
        // gramatical marcat pe cifre brute ("X on hand, Y requested").
        'insufficient_stock' => 'Not enough stock on hand to ship this: :available on hand, :requested requested.',
        'retry_requires_label_failed' => 'Only a shipment whose label failed can be retried (currently :status).',
        'line_no_room' => 'This line no longer has room for this shipment — discard it and create a new one for what is actually left.',
    ],

    'invoices' => [
        'invalid_status_for_creation' => 'An invoice can only be created from a confirmed or fulfilled order (current status: :status).',
        'already_has_active' => 'This order already has an active invoice. Void it before creating a new one.',
        'only_draft_can_be_sent' => 'Only a draft invoice can be marked as sent.',
        'only_sent_or_overdue_can_receive_payment' => 'A payment can only be recorded against a sent or overdue invoice.',
        // `:amount`/`:balance` sunt pasate deja formatate (`'$'.$amount`), EXACT ca înainte
        // de mutare — formatarea locale-aware a monedei (FR-I18N-03) e domeniul Valului 3
        // (frontend, `resources/js/lib/money.ts`), nu al acestui lot de backend.
        'payment_exceeds_balance' => 'This payment (:amount) exceeds the remaining balance (:balance).',
        'already_void' => 'This invoice is already void.',
    ],

    'shipping' => [
        'api_key_required' => 'Add a Shippo API key before activating this provider.',
        'sandbox_key_only' => 'Only Shippo sandbox keys (shippo_test_...) are accepted in this deployment — never a live key.',
    ],

    'imports' => [
        'concurrency_limit' => 'This workspace already has an import in progress. Finish or wait for it to complete before starting another (only one active import per workspace).',
        'already_finished' => 'This import has already finished and cannot be cancelled.',
        'cannot_commit' => 'This import cannot be committed from its current status.',
        'cannot_validate' => 'This import cannot start validation from its current status.',
        'processing_in_background' => 'This import is currently being processed in the background — wait for it to finish before changing the mapping.',
    ],

    'gdpr' => [
        'export_already_running' => 'This workspace already has a data export running. Wait for it to finish before requesting another one.',
    ],

    'bulk' => [
        'role_cap_exceeded' => "This operation would affect :count row, above your role's limit of :limit rows per operation.|This operation would affect :count rows, above your role's limit of :limit rows per operation.",
        'demo_row_cap' => 'This operation would touch :count row. The public demo caps bulk operations at :cap rows.|This operation would touch :count rows. The public demo caps bulk operations at :cap rows.',
        'confirmation_required' => 'This operation would affect :count row and needs confirmation before it can start.|This operation would affect :count rows and needs confirmation before it can start.',
        'concurrency_limit' => 'You already have :limit bulk operation running. Wait for one to finish (or cancel it) before starting another.|You already have :limit bulk operations running. Wait for one to finish (or cancel it) before starting another.',
    ],

    /*
     * Refuzurile de pe fluxul de membri (§6.4, §7.4, BR-TEN-01/02/06) — adăugate după
     * Valul 3, când s-a văzut că sunt singura familie de text vizibil rămasă integral în
     * engleză. Stau AICI, nu într-un fișier propriu, fiindcă sunt exact ce descrie
     * docblock-ul acestui catalog: reguli scrise manual, care depind de STAREA curentă a
     * datelor (ultimul Owner activ, invitație deja acceptată, membru deja dezactivat), nu
     * de forma câmpurilor trimise. Sursa lor e `Illuminate\Auth\Access\Response::deny()`
     * din `App\Policies\MembershipPolicy` și `App\Actions\Members\UpdateMemberRoleAction`,
     * plus patru `withErrors()` din controllere — toate ajung în același loc: alerta din
     * dialogul de pe `Settings/Members`, nu un 403 opac (vezi nota de accesibilitate P1 din
     * `MembersController::deactivate()`).
     *
     * `:owner`/`:manager` NU sunt scrise literal: vin din `lang/{locale}/roles.php` prin
     * `__('roles.owner')` la apelant, ca peste tot unde un nume de rol apare într-o frază
     * (`App\Support\DemoMode`, `MembershipRecordsNeedNewOwnerNotification`) — decizie a
     * proprietarului din 2026-09-21, o singură sursă pentru numele rolurilor. În engleză
     * substituția dă exact literalul dinainte, caracter cu caracter; 13 aserțiuni din
     * `tests/Feature/Members/*` și `tests/Feature/Rbac/MembershipPolicyTest` îl compară
     * literal și sunt garda care dovedește asta.
     *
     * `already_deactivated` e o singură cheie pentru DOUĂ surse (Policy și verificarea de
     * concurență din `MembersController::applyDeactivation()`) — același text, deci același
     * șir sursă, tradus o dată. Erau deja duplicate ca literal înainte.
     */
    'members' => [
        'cannot_invite' => 'You cannot invite members to this workspace.',
        'owner_invites_owner' => 'Only an :owner can invite another :owner.',
        'invitation_not_pending' => 'This invitation is no longer pending.',
        'invitation_expired' => 'This invitation has expired. Ask for a new one.',
        'invitation_invalid' => 'This invitation is no longer valid. Ask for a new one.',
        'cannot_change_roles' => 'You cannot change roles in this workspace.',
        'owner_changes_owner' => 'Only an :owner can promote or demote another :owner.',
        'last_owner_required' => 'A workspace needs at least one :owner.',
        'cannot_deactivate' => 'You cannot deactivate members in this workspace.',
        'owner_deactivates_owner' => 'Only an :owner can deactivate another :owner.',
        'already_deactivated' => 'This member is already deactivated.',
        'transfer_ownership_first' => 'Transfer ownership before deactivating the last :owner.',
        'cannot_deactivate_self' => "You can't deactivate yourself. Ask another :owner or :manager.",
        'no_longer_a_member' => 'This member no longer exists in this workspace.',
    ],

];
