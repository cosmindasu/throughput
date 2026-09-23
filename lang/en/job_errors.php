<?php

/**
 * I18N-03 — catalogul `App\Support\JobErrorMessage` pentru `error_message` (coloana `text`
 * a `bulk_operations`/`shipments`/`report_runs`/`data_export_requests`), scris de joburi
 * care rulează pe coadă, fără nicio cerere HTTP — vezi docblock-ul `JobErrorMessage` pentru
 * de ce jobul NU traduce, doar codifică cheia și parametrii. Sursa de adevăr e ACEST fișier:
 * `php artisan i18n:coverage` compară simetric cu `lang/fr/job_errors.php`.
 *
 * Patru registre, câte unul per familie de job:
 *  - `bulk` — `App\Jobs\Bulk\PlanBulkOperationJob`, `App\Jobs\System\FailStuckBulkOperationsJob`;
 *  - `shipment` — `App\Jobs\Shipping\GenerateShippingLabelJob`. NU acoperă mesajele
 *    RAPORTATE de un transportator (`App\Services\Shipping\ShippingLabelFailed`) — acelea
 *    sunt text extern, dinamic, deliberat necodificat (vezi docblock-ul
 *    `ShippingCarrier`/`JobErrorMessage`), trec neschimbate prin `JobErrorMessage::render()`;
 *  - `export` — `App\Jobs\Exports\ExportListJob` (exporturile de LISTĂ — conturi, comenzi
 *    etc. — distincte de exportul GDPR de mai jos);
 *  - `gdpr_export` — `App\Jobs\Gdpr\{PlanDataExportJob,ExportTenantEntityJob,
 *    FinalizeDataExportJob}` (arhiva de portabilitate, FR-GDPR-01);
 *  - `report` — `App\Jobs\Reports\GenerateReportJob`.
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
    ],

    'shipment' => [
        'generic_failure' => 'The carrier could not create a label. Try again or contact support.',
        'order_no_longer_open' => 'This order is no longer open for shipping (status: :status).',
    ],

    'export' => [
        'row_cap_exceeded' => 'This export now has :count rows; :format export is capped at :cap. Use CSV for larger exports.',
        'zip_not_supported' => 'This list cannot be exported as a zip archive. Use CSV instead.',
        'list_failed' => 'This export could not be completed. Try again from the list.',
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
    ],

];
