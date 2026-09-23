<?php

namespace Tests\Feature\Ops;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use PDOException;
use RuntimeException;
use Tests\TestCase;

/**
 * OPS-04 (audit infra 2026-09-23) — `/up` verifica doar ca PHP a bootat, nu ca
 * Postgres/Redis raspund efectiv. Fix-ul e listenerul pe `DiagnosingHealth` legat in
 * `bootstrap/app.php` (comentariul de acolo explica de ce e un callback inline, nu o
 * clasa in `app/Listeners`, si de ce e legat prin `->booted()`).
 *
 * Sursa de adevar pentru raspunsul 200/500 e `ApplicationBuilder::buildRoutingCallback()`
 * (vendor/laravel/framework), citita direct, nu presupusa: cu `APP_DEBUG=false`, orice
 * excepție aruncată de listener e prinsă și transformată în 500; cu `APP_DEBUG=true`
 * (cazul implicit din `.env.testing`), excepția e re-aruncată mai departe — de aici
 * `config(['app.debug' => false])` explicit în testele de mai jos, ca să verificăm
 * comportamentul care chiar contează în producție (`APP_DEBUG=false` acolo).
 */
class HealthCheckTest extends TestCase
{
    public function test_up_responds_200_when_postgres_and_redis_are_both_reachable(): void
    {
        $this->get('/up')->assertOk();
    }

    public function test_up_responds_500_when_postgres_is_down(): void
    {
        config(['app.debug' => false]);

        // Swap-ul facade-ului DB afectează doar restul acestui test (fiecare test Pest
        // pornește o aplicație nouă — vezi docblock-ul clasei), iar `beginDatabaseTransaction()`
        // din `RefreshDatabase` a capturat deja managerul REAL într-o closure înainte de
        // acest mock, deci tranzacția de test tot se rostogolește înapoi normal la tearDown.
        DB::shouldReceive('connection->getPdo')
            ->once()
            ->andThrow(new PDOException('SQLSTATE[08006] connection refused'));

        $this->get('/up')->assertStatus(500);
    }

    public function test_up_responds_500_when_redis_is_down(): void
    {
        config(['app.debug' => false]);

        Redis::shouldReceive('connection->ping')
            ->once()
            ->andThrow(new RuntimeException('Redis connection refused'));

        $this->get('/up')->assertStatus(500);
    }
}
