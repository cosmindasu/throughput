<?php

use Database\Migrations\Concerns\EnablesRowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Grupul B din plan §7.6.
 *
 * ATENȚIE: `memberships` e SINGURA tabelă care nu folosește politica uniformă
 * `$this->enableRls()`. Motivul e funcțional, nu tehnic (ADR-014, pct. 2): comutatorul de
 * workspace (FR-TEN-01) întreabă „în ce organizații sunt membru", o interogare cross-tenant
 * prin natura ei. Sub politica uniformă, un utilizator membru în două organizații vede
 * 0 rânduri fără context și 1 cu contextul primului — al doilea workspace devine
 * nedescoperibil, deci FR-TEN-01 nu se poate implementa deloc.
 *
 * Izolarea nu slăbește: un coleg din prima organizație NU vede membership-urile mele din
 * a doua (verificat pe toate cele șase cazuri din ADR-014, reluate în WorkspaceSwitcherTest).
 */
return new class extends Migration
{
    use EnablesRowLevelSecurity;

    public function up(): void
    {
        Schema::create('memberships', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();

            // active / pending / deactivated. `deactivated` nu e un DELETE fizic:
            // rândul rămâne, cu rolul și data aderării, pentru istoric auditabil
            // (BR-TEN-04, ADR-011).
            $table->string('status')->default('active');

            $table->string('invitation_token')->nullable();
            $table->timestamp('invitation_expires_at')->nullable();
            $table->timestamp('deactivated_at')->nullable();
            $table->foreignUlid('deactivated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'user_id']);
            $table->index(['tenant_id', 'status']);
        });

        $this->enableRlsWithPolicy('memberships', <<<'SQL'
                user_id::text   = current_setting('app.user_id',   true)
             OR tenant_id::text = current_setting('app.tenant_id', true)
            SQL, 'membership_visibility');
    }

    public function down(): void
    {
        Schema::dropIfExists('memberships');
    }
};
