<?php

/**
 * FR-GDPR-01, plan §11, FR-I18N-04 — Lotul I18N Val 5 (a treia trecere): etichetele
 * (`label`) și notele explicative (`note`) ale celor șapte surse ale exportului GDPR
 * (`App\Actions\Gdpr\DataExportSources::all()`), scrise anterior direct în cod, în engleză.
 * `name` (cheia mașină — `accounts`, `contacts`, …) NU e aici: rămâne string-ul tehnic din
 * `DataExportSource->name`, folosit ca nume de fișier și cheie de manifest, niciodată text
 * vizibil.
 *
 * Ambele ajung DOAR în `manifest.json`, din arhiva pe care persoana vizată o descarcă
 * (GDPR Art. 20) — randate în limba celui care a CERUT exportul (`users.locale` al
 * `data_export_requests.requested_by`), rezolvată de `App\Jobs\Gdpr\PlanDataExportJob` și
 * transmisă mai departe ca scalar de constructor ȘI lui `App\Jobs\Gdpr\ExportTenantEntityJob`
 * (care scrie efectiv `label`/`note` în `{entitate}.meta.json`), ȘI lui
 * `App\Jobs\Gdpr\FinalizeDataExportJob` (care compune `manifest.json` din acele fișiere) —
 * vezi docblock-urile ambelor joburi pentru motivul exact (worker de coadă de viață lungă,
 * `.ai/rules/tenancy.md:123-138`).
 *
 * Etichetele `accounts`/`contacts`/`deals`/`orders`/`invoices`/`activity_log` refolosesc
 * EXACT formularea deja stabilită în `lang/fr/search.php` (`groups.*`) și
 * `resources/js/locales/fr/common.json` (`nav.*`) — ca să nu existe două traduceri
 * diferite pentru același obiect pe ecrane vecine (deja o problemă reală în acest lot, pe
 * „deal"/„affaire" — vezi `lang/fr/rules.php`).
 *
 * Valorile engleze sunt copii IDENTICE, caracter cu caracter, ale literalelor din
 * `DataExportSources::all()` dinainte de extragere.
 */
return [

    'sources' => [
        'accounts' => [
            'label' => 'Accounts',
            'note' => 'Every company record in this workspace. Billing address, shipping address and tags are structured objects, which is why this entity is JSON only — a spreadsheet column would have flattened them into text.',
        ],

        'contacts' => [
            'label' => 'Contacts',
            'note' => 'Every person recorded against an account. Contacts that were anonymised under the right to erasure are not here: their identifying fields were already cleared, so the row that remains carries no personal data to hand over.',
        ],

        'deals' => [
            'label' => 'Deals',
            'note' => 'Every deal, including the ones that were deleted from the board: a deleted deal is still stored, so it is still data held about you. Those rows carry a "deleted_at" date; the live ones have it empty.',
        ],

        'orders' => [
            'label' => 'Orders',
            'note' => 'Every order, with its lines nested under "order_lines" in the JSON file. The CSV holds the order rows only — one row per order, without the lines, because a line-per-row table would repeat every order total.',
        ],

        'invoices' => [
            'label' => 'Invoices',
            'note' => 'Every invoice raised against an order. The generated PDF itself is not in the archive — it is a rendering of these same figures, and a PDF does not count as a machine-readable format for portability.',
        ],

        'payments' => [
            'label' => 'Payments',
            'note' => 'Every payment recorded against an invoice. Payments are entered by hand in this product, so there is no card or bank data of any kind to export.',
        ],

        'activity_log' => [
            'label' => 'Activity log',
            'note' => 'Who changed what, and when. Entries older than the retention window have their before/after values replaced with "[anonymized]" — the shape of the entry survives, the values do not. JSON only, because those before/after values are structured objects.',
        ],
    ],

];
