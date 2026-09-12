<?php

namespace Tests\Feature\Tenancy;

use App\Models\Scopes\TenantScope;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

/**
 * Unul dintre cele patru teste din plan §7.9 care există pentru că au fost ratate de
 * citirea codului până la verificarea din 2026-09-12 ([[ADR-014]]): fiecare ar fi prins
 * un bug real, deja livrat ca „reparat".
 *
 * Aici: contextul se resetează efectiv la commit. Prinde regresia `set_config(..., false)`
 * și orice revenire la `SET LOCAL` cu parametri legați (care nici măcar nu rulează).
 */
class SessionContextTest extends TestCase
{
    /**
     * Fără împachetarea în tranzacție a harnessului: sub ea, un „commit" din cod e doar
     * eliberarea unui savepoint, iar valoarea ar supraviețui oricum. Testul ar fi trecut
     * verde măsurând harnessul, nu mecanismul.
     */
    protected bool $wrapInTransaction = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clearDatabaseTenantContext();
    }

    public function test_the_tenant_context_is_scoped_to_the_transaction(): void
    {
        $tenantId = (string) Str::ulid();

        TenantContext::run($tenantId, function () use ($tenantId): void {
            $this->assertSame($tenantId, TenantContext::currentDatabaseSetting('tenant_id'));
        });

        // Aceeași conexiune, după commit. Dacă aici ar apărea ULID-ul de mai sus,
        // conexiunea reutilizată de PHP-FPM sau de un worker Horizon ar moșteni tenantul
        // cererii anterioare — chiar scurgerea pe care RLS ar trebui s-o prevină.
        $this->assertNull(TenantContext::currentDatabaseSetting('tenant_id'));
    }

    public function test_the_user_context_is_scoped_to_the_transaction(): void
    {
        $userId = (string) Str::ulid();

        TenantContext::openFor($userId, function () use ($userId): void {
            $this->assertSame($userId, TenantContext::currentDatabaseSetting('user_id'));
        });

        $this->assertNull(TenantContext::currentDatabaseSetting('user_id'));
    }

    public function test_a_rollback_also_discards_the_context(): void
    {
        $tenantId = (string) Str::ulid();

        try {
            TenantContext::run($tenantId, function (): void {
                throw new LogicException('eșec simulat la mijlocul jobului');
            });
        } catch (LogicException) {
            // Așteptat: ne interesează ce rămâne pe conexiune după rollback.
        }

        $this->assertNull(TenantContext::currentDatabaseSetting('tenant_id'));
        $this->assertNull(app()->bound(TenantScope::CONTAINER_KEY) ? app(TenantScope::CONTAINER_KEY) : null);
    }

    public function test_setting_the_context_outside_a_transaction_is_refused(): void
    {
        // `set_config(..., true)` în afara unei tranzacții e un no-op tăcut: stratul 1 ar
        // rămâne legat de tenant, stratul 2 nu. Mai bine o excepție decât o interogare
        // nescopată câteva linii mai încolo.
        $this->assertSame(0, DB::transactionLevel());

        $this->expectException(LogicException::class);

        TenantContext::setTenant((string) Str::ulid());
    }
}
