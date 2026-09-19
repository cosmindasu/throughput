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

        // Export PDF (§13.5, Orders) — mereu în coadă (ADR-013: DomPDF randează HTML în
        // proces, un cost care nu are ce căuta în tranzacția cererii), deci pragul de mai
        // sus nu i se aplică. **250, coborât de la 500** — RECONFIRMAT PE CONTAINER (Faza 4),
        // nu pe Mac, cum fuseseră cifrele din ADR-019: `php:8.3-fpm-alpine` cu extensiile din
        // `docker/app/Dockerfile`, `--memory=384m` (bugetul real al lui `horizon`), pornind de
        // la un worker de coadă REAL (`ExportListJob` dispecerizat pe coada `bulk`, nu un
        // script gol), pe aceeași sursă (`OrderList`/`PdfExporter`), 2 repetări per punct.
        // Vârful e RSS-ul procesului citit din `/proc/<pid>/status` (`VmHWM`), nu
        // `memory_get_peak_usage()`:
        //   100 → 0,7s / 108 MB · 250 → 1,2s / 162 MB · 500 → 3,0s / 294 MB
        //   750 → 4,3s / ~400 MB, UCIS DE KERNEL (`docker inspect` → `OOMKilled: true`)
        // Un worker pornit, cu coada goală, ÎNAINTE de primul rând de PDF, are deja ~58 MB
        // (framework, driver de bază de date, conexiunea Redis) — de aici diferența față de
        // cifrele de heap din ADR-019, care rămân corecte pentru ce măsurau.
        //
        // Măsurătoarea a scos la iveală un defect mai mare, reparat separat: `memory_limit`
        // PHP era 128M și în containerul `horizon`, care are 384m, fiindcă aceeași imagine
        // servește și `app` (256m, 4 copii FPM). Adică `'memory' => 256` din
        // `config/horizon.php` — pragul măsurat și argumentat în Faza 3 — nu putea intra
        // NICIODATĂ în vigoare: PHP omora procesul înainte ca Horizon să verifice. Peste ~225
        // de rânduri, exportul pica cu „Allowed memory size exhausted", iar fatalul NU ajunge
        // în `catch (Throwable $e)` din `ExportListJob`: operația rămânea „running", cu polling
        // la nesfârșit, până la epuizarea celor 3 încercări (~45 de minute). Fixul e un
        // `memory_limit=256M` activat prin `PHP_INI_SCAN_DIR` DOAR pe serviciul `horizon` —
        // vezi `docker/app/Dockerfile`, stage-ul `base`, pentru de ce nu merge prin `conf.d`
        // și de ce nu merge nici prin `php -d`.
        //
        // 250, cu fixul aplicat: ~162 MB RSS, din care ~127 MB heap — peste 2× marjă sub
        // plafonul de 256M. 500 de rânduri ar cere ~259 MB de heap, adică exact peste plafon,
        // deci valoarea veche rămâne nesigură chiar și după fix.
        'export_pdf_max_rows' => (int) env('EXPORT_PDF_MAX_ROWS', 250),

        // FR-GDPR-01 (specs.md §20.5): link de descărcare valabil 7 zile. Aceeași valoare
        // pentru exportul de listă (`bulk_operations`, §13.2) — `PruneExpiredExportsJob`
        // (plan §7.2) golește `result_path` peste acest prag; fișierul dispare de pe disc.
        'export_retention_days' => (int) env('EXPORT_RETENTION_DAYS', 7),

        // BR-BULK-02 — plafonul DUR al Agentului pe o operație în masă de SCRIERE, per
        // operație, indiferent de resursă (conturi/deals/comenzi). Distinct de
        // `bulk_max_rows` de mai sus: acela e plafonul ABSOLUT din DEMO_MODE, pentru orice
        // rol; ăsta e o regulă de business permanentă, activă și în afara demo-ului.
        // `App\Support\Bulk\BulkConfirmationThreshold` calculează pragul de confirmare
        // (FR-BULK-01) din valoarea asta — 25% din ea, plafonat la 1.000.
        'bulk_agent_row_cap' => (int) env('BULK_AGENT_ROW_CAP', 500),

        // §13.2, pct. 2 — mărimea unui chunk de operație în masă (planul cere 500-1.000
        // rânduri per job de coadă). Un singur loc: planificatorul (`PlanBulkOperationJob`)
        // și estimarea de progres (`BulkOperationResource`) trebuie să vadă aceeași valoare.
        'bulk_chunk_size' => (int) env('BULK_CHUNK_SIZE', 500),

        // §13.2 (code review, fix operațional) — o operație de SCRIERE rămasă `pending`/
        // `running` FĂRĂ `batch_id` mai mult decât atât e considerată blocată (procesul a
        // murit exact între `Bus::batch()->dispatch()` și scrierea `batch_id`) —
        // `App\Jobs\System\FailStuckBulkOperationsJob` o închide ca `failed`. 15 minute:
        // mult peste orice timp normal de planificare (`PlanBulkOperationJob::timeout` =
        // 120s), deci nu atinge niciodată o operație încă în curs de planificare legitimă.
        'bulk_stuck_operation_minutes' => (int) env('BULK_STUCK_OPERATION_MINUTES', 15),
    ],

];
