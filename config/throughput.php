<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Modul de demonstrație — §22 din specs.md, BR-PUB-01
    |--------------------------------------------------------------------------
    |
    | Producția ESTE demo-ul public, cu scriere reală: orice vizitator poate apăsa
    | butoane. De aici reset-ul zilnic, interceptarea email-ului și guardrail-urile
    | distructive. Fără ele, primul vizitator produce efecte reale.
    |
    | Valorile se citesc prin `config()`, NU prin `env()` din cod: entrypoint-ul de
    | producție rulează `config:cache`, iar după el `env()` întoarce implicitul —
    | adică `DEMO_MODE` ar fi devenit tăcut `false` exact în singurul mediu unde
    | contează. Un apel `env()` în afara fișierelor de config e un bug, nu un stil.
    |
    */

    'demo' => [

        'mode' => (bool) env('DEMO_MODE', false),

        // Domenii și adrese care primesc email REAL. Restul se interceptează (§22.3).
        'email_allowlist' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('DEMO_EMAIL_ALLOWLIST', ''))
        ))),

        // Reset zilnic al setului de date de demonstrație (§22.1, FR-DEMO-03).
        'reset_cron' => env('DEMO_RESET_CRON', '0 3 * * *'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Plafoane operaționale — §2.1 din plan-implementare.md
    |--------------------------------------------------------------------------
    |
    | `bulk_max_rows` e plafonul ABSOLUT, deliberat 3× peste ținta KPI de 20.000:
    | fixat pe ținta KPI, criteriul ar fi devenit nedemonstrabil în singurul mediu
    | real (finding P2-004 din auditul specificației).
    |
    */

    'limits' => [
        'bulk_max_rows' => (int) env('BULK_MAX_ROWS_ABSOLUTE', 60000),
        'import_max_rows' => (int) env('IMPORT_MAX_ROWS', 50000),
        'api_rate_limit_per_minute' => (int) env('API_RATE_LIMIT_PER_MINUTE', 300),

        // US-CRM-03, §13.2: sub prag, exportul CSV răspunde sincron în cererea curentă;
        // peste el, devine un `bulk_operations` + job pe coada `bulk` (§13.2, ADR-013 —
        // scrierea fișierului nu are voie să țină tranzacția cererii deschisă).
        'export_sync_max_rows' => (int) env('EXPORT_SYNC_MAX_ROWS', 5000),

        // FR-GDPR-01 (specs.md §20.5): link de descărcare valabil 7 zile. Aceeași valoare
        // pentru exportul de listă (`bulk_operations`, §13.2) — `PruneExpiredExportsJob`
        // (plan §7.2) golește `result_path` peste acest prag; fișierul dispare de pe disc.
        'export_retention_days' => (int) env('EXPORT_RETENTION_DAYS', 7),
    ],

];
