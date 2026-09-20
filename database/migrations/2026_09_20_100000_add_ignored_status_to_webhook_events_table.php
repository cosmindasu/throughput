<?php

use App\Models\WebhookEvent;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * §12.3 — statusul `ignored` pe `webhook_events`, cerut de o decizie operațională a
 * proprietarului (2026-09-20): sandbox-ul Stripe rămâne ÎMPĂRȚIT cu alt proiect, deci
 * endpoint-ul nostru primește, cu semnătură perfect validă, evenimentele acelui proiect.
 * Până acum ramura „niciun tenant nu se mapează pe acest customer" le scria `failed`, ceea
 * ce e greșit ca model: semnătura E validă, evenimentul pur și simplu NU NE PRIVEȘTE.
 * Consecința practică e că ecranul de operare (§25.2, „orice eveniment `failed` neresolvat
 * > 1 oră") s-ar umple de roșu care nu e al nostru — iar un roșu care nu înseamnă nimic
 * face inutil roșul care înseamnă ceva.
 *
 * `status` e un `enum` Laravel, adică pe PostgreSQL un `varchar` + CHECK constraint
 * (`webhook_events_status_check`, verificat în `pg_constraint`), NU un tip `ENUM` nativ:
 * deci se înlocuiește constrângerea, nu se adaugă o valoare la un tip. `Schema::table(...
 * ->enum(...)->change())` ar fi cerut `doctrine/dbal` (nu e instalat, și nici nu-l cerem —
 * `.ai/rules/project.md`: niciun pachet nou fără argument), deci SQL direct, ca în
 * `2026_09_19_190000_add_lower_name_index_...`.
 *
 * FĂRĂ backfill: tabela e goală în producție (proiectul nu e lansat), iar rândurile
 * existente în mediile de test sunt `received`/`processed`/`failed` — toate rămân valide
 * sub constrângerea nouă, care doar ADAUGĂ o valoare.
 *
 * `down()` rescrie `ignored → failed` ÎNAINTE de a reîngusta constrângerea — altfel
 * ALTER TABLE ar eșua pe orice rând scris între timp (PostgreSQL validează CHECK-ul nou
 * pe datele existente). Rollback-ul pierde deci distincția, nu rândurile.
 */
return new class extends Migration
{
    private const CONSTRAINT = 'webhook_events_status_check';

    public function up(): void
    {
        $this->replaceStatusCheck([
            WebhookEvent::STATUS_RECEIVED,
            WebhookEvent::STATUS_PROCESSING,
            WebhookEvent::STATUS_PROCESSED,
            WebhookEvent::STATUS_FAILED,
            WebhookEvent::STATUS_IGNORED,
        ]);
    }

    public function down(): void
    {
        DB::table('webhook_events')
            ->where('status', WebhookEvent::STATUS_IGNORED)
            ->update(['status' => WebhookEvent::STATUS_FAILED]);

        $this->replaceStatusCheck([
            WebhookEvent::STATUS_RECEIVED,
            WebhookEvent::STATUS_PROCESSING,
            WebhookEvent::STATUS_PROCESSED,
            WebhookEvent::STATUS_FAILED,
        ]);
    }

    /**
     * @param  list<string>  $statuses
     */
    private function replaceStatusCheck(array $statuses): void
    {
        $values = implode(', ', array_map(static fn (string $status) => "'{$status}'", $statuses));

        DB::statement('ALTER TABLE webhook_events DROP CONSTRAINT IF EXISTS '.self::CONSTRAINT);
        DB::statement(
            'ALTER TABLE webhook_events ADD CONSTRAINT '.self::CONSTRAINT.
            " CHECK (status::text = ANY (ARRAY[{$values}]::text[]))"
        );
    }
};
