<?php

/**
 * I18N-03 — catalogul `App\Support\JobErrorMessage` pentru `error_message` (coloana `text`
 * a `bulk_operations`/`shipments`/`report_runs`/`data_export_requests`), scris de joburi
 * care rulează pe coadă, fără nicio cerere HTTP — vezi docblock-ul `JobErrorMessage` pentru
 * de ce jobul NU traduce, doar codifică cheia și parametrii. Sursa de adevăr e ACEST fișier:
 * `php artisan i18n:coverage` compară simetric cu `lang/fr/job_errors.php`.
 *
 * Șase registre, câte unul per familie de scriitor:
 *  - `bulk` — `App\Jobs\Bulk\PlanBulkOperationJob`, `App\Jobs\System\FailStuckBulkOperationsJob`;
 *  - `shipment` — `App\Jobs\Shipping\GenerateShippingLabelJob`. Mesajul RAPORTAT de un
 *    transportator (`App\Services\Shipping\ShippingLabelFailed`) e text extern, necatalogabil:
 *    intră cuvânt cu cuvânt ca `:reason` în cadrul tradus `carrier_rejected`;
 *  - `export` — `App\Jobs\Exports\ExportListJob` (exporturile de LISTĂ — conturi, comenzi
 *    etc. — distincte de exportul GDPR de mai jos);
 *  - `gdpr_export` — `App\Jobs\Gdpr\{PlanDataExportJob,ExportTenantEntityJob,
 *    FinalizeDataExportJob}` (arhiva de portabilitate, FR-GDPR-01);
 *  - `report` — `App\Jobs\Reports\GenerateReportJob`;
 *  - `webhook` — `App\Http\Controllers\Webhooks\StripeWebhookController::ignore()` (coloana
 *    `webhook_events.error_message`, randată în ecranul Webhook health). Mesajele brute ale
 *    `ProcessStripeWebhookJob::failed()` rămân necodificate, deliberat (excepție documentată
 *    în garda `error_message` din `ArchitectureTest`), și trec neschimbate prin `render()`.
 *
 * `:status` (registrul `shipment`) e statusul unei comenzi — `App\Enums\OrderStatus` — dar
 * NU se traduce aici: jobul îl trimite prin `JobErrorMessage::translatedParam()`, ca cheie
 * de catalog amânată către `lang/{en,fr}/enums.php`, deci ajunge deja tradus la `__()`, nu
 * ca valoare brută a enum-ului (`confirmed`). Vezi docblock-ul `JobErrorMessage`.
 */
return [

    'bulk' => [
        'initiator_gone' => 'The member who started this operation is no longer available.',
        'stuck_operation' => 'This operation could not start. Please try again.',
        // I18N-03 (P2, lot i18n) — plasa de siguranță a catch-ului generic din
        // `PlanBulkOperationJob::handle()`: o `Throwable` NEAȘTEPTATĂ (nu una din
        // ramurile deja cataloage de mai sus) nu-și mai scrie `getMessage()` brut pe
        // coloană — poate conține SQL/căi interne. Textul original rămâne doar pentru
        // `report()`/Sentry.
        'unexpected' => 'This operation failed due to an unexpected error. Try again or contact support if it keeps happening.',
    ],

    'shipment' => [
        'generic_failure' => 'The carrier could not create a label. Try again or contact support.',
        'carrier_rejected' => 'The carrier rejected the label: :reason',
        'order_no_longer_open' => 'This order is no longer open for shipping (status: :status).',
    ],

    'export' => [
        'row_cap_exceeded' => 'This export now has :count rows; :format export is capped at :cap. Use CSV for larger exports.',
        'zip_not_supported' => 'This list cannot be exported as a zip archive. Use CSV instead.',
        'list_failed' => 'This export could not be completed. Try again from the list.',
        // I18N-03 (P2, lot i18n) — pereche a lui `bulk.unexpected`, pentru catch-ul
        // generic din `ExportListJob::handle()`.
        'unexpected' => 'This export failed due to an unexpected error. Try again or contact support if it keeps happening.',
    ],

    'gdpr_export' => [
        'nothing_delivered' => 'The export could not be completed. Nothing was delivered; request a new export to try again.',
        'packaging_failed' => 'The export could not be packaged. Nothing was delivered; request a new export to try again.',
        'could_not_start' => 'The export could not be started. Try again.',
        'entity_write_failed' => 'The export stopped while writing the ":entity" file. Nothing was delivered; request a new export to try again.',
    ],

    'report' => [
        'definition_missing' => 'The report definition no longer exists.',
        'pdf_row_cap_exceeded' => 'This report has :count rows; PDF is capped at :cap. Use CSV or XLSX for larger reports.',
        'xlsx_row_cap_exceeded' => 'This report has :count rows; XLSX is capped at :cap. Use CSV for larger reports.',
        'generation_failed' => 'This report could not be generated. Try running it again.',
        // I18N-03 (P2, lot i18n) — pereche a lui `bulk.unexpected`, pentru ramura „orice
        // altă Throwable" a ternarului din `GenerateReportJob::handle()` (distinctă de
        // `ReportRowCapExceededException`, care poartă deja o cheie proprie).
        'unexpected' => 'This report failed due to an unexpected error. Try again or contact support if it keeps happening.',
    ],

    'webhook' => [
        'no_customer' => 'Not for this deployment: the event has no data.object.customer, so there is no workspace it could belong to.',
        'unknown_customer' => 'Not for this deployment: Stripe customer :customer does not belong to any workspace here. The Stripe sandbox is shared with another project, so its events arrive at this endpoint too.',
    ],

];
