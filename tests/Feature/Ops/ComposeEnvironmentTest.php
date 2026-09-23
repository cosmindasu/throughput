<?php

namespace Tests\Feature\Ops;

use Tests\TestCase;

/**
 * OPS — garda pentru clasa de bug găsită pe `CASHIER_CURRENCY`: Coolify injectează în
 * containere DOAR variabilele REFERITE în ancora `x-app-env` din
 * `docker-compose.coolify.yml`, indiferent ce e setat în Coolify -> Environment
 * Variables. `vendor/laravel/cashier/config/cashier.php` citește
 * `env('CASHIER_CURRENCY', 'usd')` — fără o linie `CASHIER_CURRENCY: "${CASHIER_CURRENCY}"`
 * în ancoră, producția rula tăcut cu dolari, cu produsele de test Stripe în euro
 * (`.env.example` explică de ce contează). Niciun test funcțional n-ar fi prins asta:
 * `CASHIER_CURRENCY` nu lipsește din `.env.example` (unde e documentată corect, `eur`),
 * lipsește doar din PODUL dintre `.env.example` și container.
 *
 * Testul de mai jos face genul ăsta de gaură imposibil de repetat TĂCUT: fiecare cheie
 * din `.env.example` e fie referită (`${NUME`) undeva în `docker-compose.coolify.yml`,
 * fie listată explicit în `SAFE_TO_OMIT`, cu motivul EXACT pentru care absența ei e
 * sigură — verificat citind config-ul sau codul relevant, nu presupus.
 *
 * Parsare deliberat simplă și robustă, exact cum a cerut lotul: chei de forma
 * `^[A-Z][A-Z0-9_]*=` în `.env.example` (ignoră comentarii și linii goale), `${NUME`
 * oriunde în `docker-compose.coolify.yml` (interpolarea shell a Docker Compose). O
 * cheie prezentă ca valoare FIXĂ în `x-app-env` (ex. `DB_HOST: postgres`, nu
 * `${DB_HOST}`) NU „trece” automat verificarea de referință — apare mai jos, cu motivul
 * „valoare fixă, nu variabilă Coolify”: nu lipsește din container, doar nu vine din
 * UI-ul Coolify, deliberat (nume de serviciu intern sau decizie de securitate).
 */
class ComposeEnvironmentTest extends TestCase
{
    /**
     * Cheie => motivul EXACT pentru care absența ei din `x-app-env` e sigură astăzi.
     * Fiecare intrare a fost verificată (config citit, cod citit, valoare comparată),
     * nu presupusă — dacă vreuna devine falsă (config schimbat, variabilă folosită
     * altundeva), garda de mai jos pică pe cheia respectivă, nu pe o listă întreagă.
     *
     * @var array<string, string>
     */
    private const SAFE_TO_OMIT = [
        // --- Valori FIXE în x-app-env (nu `${VAR}`) — Coolify UI n-are ce suprascrie,
        // deliberat: nume de serviciu intern Docker sau decizie de securitate/producție. ---
        'APP_NAME' => 'Valoare fixă în x-app-env ("Throughput"), nu ${VAR} — nu vine din Coolify UI.',
        'APP_ENV' => 'Valoare fixă în x-app-env ("production") — n-are voie să fie schimbabilă din UI.',
        'APP_DEBUG' => 'Valoare fixă în x-app-env ("false") — deliberat, niciodată true în producție.',
        'APP_LOCALE' => 'Valoare fixă în x-app-env ("en") — interfața e doar în engleză (specs.md §0).',
        'DB_CONNECTION' => 'Valoare fixă în x-app-env ("pgsql") — nu variază pe mediu.',
        'DB_HOST' => 'Valoare fixă în x-app-env ("postgres") — numele serviciului Docker, nu configurabil.',
        'DB_MIGRATIONS_CONNECTION' => 'Valoare fixă în x-app-env ("pgsql_migrations") — nu variază pe mediu.',
        'SESSION_DRIVER' => 'Valoare fixă în x-app-env ("redis") — nu variază pe mediu.',
        'SESSION_SECURE_COOKIE' => 'Valoare fixă în x-app-env ("true") — mai strictă decât implicitul de dezvoltare, deliberat.',
        'CACHE_STORE' => 'Valoare fixă în x-app-env ("redis") — nu variază pe mediu.',
        'QUEUE_CONNECTION' => 'Valoare fixă în x-app-env ("redis") — nu variază pe mediu.',
        'REDIS_HOST' => 'Valoare fixă în x-app-env ("redis") — numele serviciului Docker, nu configurabil.',
        'HORIZON_PREFIX' => 'Valoare fixă în x-app-env ("throughput_horizon:") — nu variază pe mediu.',

        // --- Implicit din `config/throughput.php` deja IDENTIC cu valoarea din
        // `.env.example` — verificat citind fișierul (toate sub `'limits'`/`'demo'`). ---
        'ACTIVITY_LOG_RETENTION_MONTHS' => "config/throughput.php: env('ACTIVITY_LOG_RETENTION_MONTHS', 36) — identic cu .env.example.",
        'API_RATE_LIMIT_PER_MINUTE' => "config/throughput.php: env('API_RATE_LIMIT_PER_MINUTE', 300) — identic cu .env.example.",
        'BULK_AGENT_ROW_CAP' => "config/throughput.php: env('BULK_AGENT_ROW_CAP', 500) — identic cu .env.example.",
        'BULK_CHUNK_SIZE' => "config/throughput.php: env('BULK_CHUNK_SIZE', 500) — identic cu .env.example.",
        'BULK_CONCURRENT_PER_USER' => "config/throughput.php: env('BULK_CONCURRENT_PER_USER', 3) — identic cu .env.example.",
        'BULK_MAX_ROWS_ABSOLUTE' => "config/throughput.php: env('BULK_MAX_ROWS_ABSOLUTE', 60000) — identic cu .env.example.",
        'BULK_STUCK_OPERATION_MINUTES' => "config/throughput.php: env('BULK_STUCK_OPERATION_MINUTES', 15) — identic cu .env.example.",
        'EXPORT_PDF_MAX_ROWS' => "config/throughput.php: env('EXPORT_PDF_MAX_ROWS', 250) — identic cu .env.example, cifră măsurată (vezi comentariul din config).",
        'EXPORT_RETENTION_DAYS' => "config/throughput.php: env('EXPORT_RETENTION_DAYS', 7) — identic cu .env.example.",
        'EXPORT_SYNC_MAX_ROWS' => "config/throughput.php: env('EXPORT_SYNC_MAX_ROWS', 5000) — identic cu .env.example.",
        'EXPORT_XLSX_MAX_ROWS' => "config/throughput.php: env('EXPORT_XLSX_MAX_ROWS', 5000) — identic cu .env.example.",
        'IMPORT_ABANDONED_HOURS' => "config/throughput.php: env('IMPORT_ABANDONED_HOURS', 24) — identic cu .env.example.",
        'IMPORT_CHUNK_SIZE' => "config/throughput.php: env('IMPORT_CHUNK_SIZE', 500) — identic cu .env.example.",
        'IMPORT_CONCURRENT_PER_TENANT' => "config/throughput.php: env('IMPORT_CONCURRENT_PER_TENANT', 1) — identic cu .env.example.",
        'IMPORT_MAX_FILE_MB' => "config/throughput.php: env('IMPORT_MAX_FILE_MB', 20) — identic cu .env.example.",
        'IMPORT_MAX_ROWS' => "config/throughput.php: env('IMPORT_MAX_ROWS', 50000) — identic cu .env.example.",
        'IMPORT_RETENTION_DAYS' => "config/throughput.php: env('IMPORT_RETENTION_DAYS', 7) — identic cu .env.example.",
        'IMPORT_STUCK_MINUTES' => "config/throughput.php: env('IMPORT_STUCK_MINUTES', 15) — identic cu .env.example.",
        'OVERDUE_CHUNK_SIZE' => "config/throughput.php: env('OVERDUE_CHUNK_SIZE', 500) — identic cu .env.example.",
        'SENT_EMAIL_RETENTION_DAYS' => "config/throughput.php: env('SENT_EMAIL_RETENTION_DAYS', 7) — identic cu .env.example.",

        // --- Implicit al framework-ului/pachetului deja identic — config nepublicat sau
        // valoare hardcodată, verificat direct în `vendor/` sau în codul care-l citește. ---
        'APP_FAKER_LOCALE' => "config/app.php: env('APP_FAKER_LOCALE', 'en_US') — identic cu .env.example.",
        'APP_FALLBACK_LOCALE' => "config/app.php: 'fallback_locale' implicit 'en' — identic cu .env.example.",
        'APP_MAINTENANCE_DRIVER' => "config/app.php: env('APP_MAINTENANCE_DRIVER', 'file') — identic cu .env.example.",
        'APP_TIMEZONE' => "config/app.php hardcodează 'timezone' => 'UTC' direct, fără env() — variabila n-are niciun efect, indiferent de mediu.",
        'BCRYPT_ROUNDS' => 'config/hashing.php nu e publicat — Illuminate\Hashing\BcryptHasher::$rounds implicit e 12, identic cu .env.example.',
        'BROADCAST_CONNECTION' => 'Funcționalitate neactivă (fără Reverb în MVP, .ai/rules/project.md) — niciun ShouldBroadcast/broadcast() în app/ (verificat).',
        'DB_PORT' => "config/database.php: env('DB_PORT', '5432') pentru conexiunea pgsql — identic cu .env.example; oricum trafic intern Docker.",
        'FILESYSTEM_DISK' => "config/filesystems.php: env('FILESYSTEM_DISK', 'local') — identic cu .env.example, coincide cu volumul app_storage.",
        'LOG_DEPRECATIONS_CHANNEL' => "config/logging.php: implicit 'null' — identic cu .env.example.",
        'LOG_STACK' => 'Irelevantă în producție: x-app-env suprascrie LOG_CHANNEL=stderr (vezi comentariul din acest fișier) — canalul "stack" nu se folosește.',
        'MAIL_FROM_NAME' => "config/mail.php: env('MAIL_FROM_NAME', env('APP_NAME', 'Laravel')) — APP_NAME E injectat (fix, mai sus), deci implicitul rezolvă la 'Throughput'.",
        'MAIL_SCHEME' => "config/mail.php: env('MAIL_SCHEME') fără al doilea argument — implicit null, identic cu .env.example.",
        'REDIS_CLIENT' => "config/database.php: env('REDIS_CLIENT', 'phpredis') — identic cu .env.example.",
        'REDIS_PORT' => "config/database.php: env('REDIS_PORT', 6379) — identic cu .env.example; trafic intern Docker.",
        'SESSION_DOMAIN' => 'config/session.php: implicit null — identic cu .env.example.',
        'SESSION_ENCRYPT' => 'config/session.php: implicit false — identic cu .env.example.',
        'SESSION_LIFETIME' => 'config/session.php: implicit 120 — identic cu .env.example.',
        'SESSION_PATH' => "config/session.php: implicit '/' — identic cu .env.example.",
        'VITE_APP_NAME' => "Build-time (Vite), nu runtime container — resources/js/app.tsx: `import.meta.env.VITE_APP_NAME || 'Throughput'`, fallback identic.",
    ];

    public function test_every_env_example_key_is_either_injected_by_coolify_or_explicitly_accounted_for(): void
    {
        $envExampleKeys = $this->keysFromEnvExample();
        $referencedInCompose = $this->keysReferencedInCompose();

        $missing = array_values(array_filter(
            $envExampleKeys,
            fn (string $key): bool => ! in_array($key, $referencedInCompose, true) && ! array_key_exists($key, self::SAFE_TO_OMIT),
        ));

        $this->assertSame(
            [],
            $missing,
            'Cheile de mai jos există în .env.example, NU sunt referite în docker-compose.coolify.yml '
            .'(x-app-env) și n-au un motiv documentat în ComposeEnvironmentTest::SAFE_TO_OMIT — producția '
            .'le-ar primi tăcut cu implicitul din cod, nu cu ce e setat în Coolify (exact bug-ul găsit pe '
            .'CASHIER_CURRENCY): '.implode(', ', $missing),
        );
    }

    /**
     * Harta nu are voie să acumuleze intrări moarte fără să se observe: fiecare cheie din
     * `SAFE_TO_OMIT` trebuie să existe încă în `.env.example` — dacă variabila a fost
     * eliminată de-acolo, motivul ei nu mai are ce documenta.
     */
    public function test_the_exceptions_map_does_not_carry_stale_keys(): void
    {
        $stale = array_diff(array_keys(self::SAFE_TO_OMIT), $this->keysFromEnvExample());

        $this->assertSame([], array_values($stale), 'Chei ieșite din .env.example, dar rămase în SAFE_TO_OMIT: '.implode(', ', $stale));
    }

    /** @return list<string> */
    private function keysFromEnvExample(): array
    {
        $contents = (string) file_get_contents(base_path('.env.example'));

        preg_match_all('/^([A-Z][A-Z0-9_]*)=/m', $contents, $matches);

        return array_values(array_unique($matches[1]));
    }

    /** @return list<string> */
    private function keysReferencedInCompose(): array
    {
        $contents = (string) file_get_contents(base_path('docker-compose.coolify.yml'));

        preg_match_all('/\$\{([A-Z][A-Z0-9_]*)/', $contents, $matches);

        return array_values(array_unique($matches[1]));
    }
}
