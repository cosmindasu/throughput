<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Faza 5, lotul E (§13.2 pct. 5, §13.3, US-BULK-01) — coloană DEDICATĂ pentru „ce operație
 * în masă a scris acest rând de jurnal", nu o cheie în `old_values`/`new_values`.
 *
 * De ce nu în JSON: filtrarea „activity_log pentru operația X" (linkul din US-BULK-01, cel
 * mai bun echivalent pe rândul din tabul „History" al unei entități atinse de un bulk) ar
 * cere `old_values->>'bulk_operation_id' = ?` — operatorul `jsonb ->>` NU e `LEAKPROOF`
 * (`select proleakproof from pg_proc where proname = 'jsonb_object_field_text'` → `f`),
 * deci sub RLS PostgreSQL refuză să coboare acel predicat sub politică (`tenant_isolation`),
 * exact capcana din `.ai/rules/tenancy.md` („Un index funcțional pe o funcție ne-LEAKPROOF
 * e mort sub RLS") — plătită deja o dată în Faza 4, pe cheile de duplicat ale importului.
 * O coloană proprie, comparată cu `=` simplu (`bpchareq`, LEAKPROOF), nu are acest defect.
 *
 * `tenant_id` pe prima poziție a indexului (ADR-003, §7.7 din plan) — orice interogare pe
 * `activity_log` e oricum filtrată de RLS pe tenant, deci indexul trebuie să înceapă cu
 * coloana pe care RLS filtrează, altfel planificatorul nu-l poate folosi eficient pentru
 * restul predicatului.
 *
 * `nullOnDelete()`, nu `cascadeOnDelete()`: `activity_log` e append-only (§17, App\Concerns\
 * AppendOnly pe App\Models\ActivityLog) — un rând de jurnal nu are voie să dispară pentru
 * că `bulk_operations` ar fi curățată cândva; azi nimic nu șterge `bulk_operations`
 * (`FailStuckBulkOperationsJob` doar schimbă `status`), dar legătura rămâne opțională prin
 * construcție, nu prin absența unei reguli de ștergere viitoare.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_log', function (Blueprint $table): void {
            $table->foreignUlid('bulk_operation_id')
                ->nullable()
                ->after('auditable_id')
                ->constrained('bulk_operations')
                ->nullOnDelete();

            $table->index(['tenant_id', 'bulk_operation_id']);
        });
    }

    public function down(): void
    {
        Schema::table('activity_log', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('bulk_operation_id');
        });
    }
};
