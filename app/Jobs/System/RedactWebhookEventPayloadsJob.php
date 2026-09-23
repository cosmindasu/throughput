<?php

namespace App\Jobs\System;

use App\Models\WebhookEvent;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * GDPR-03 (audit 2026-09-23, `docs/reviews/2026-09-23_audit/08-gdpr.md`, P2) —
 * `webhook_events.payload` cară Stripe complet, inclusiv `customer_email`
 * (`App\Support\Sentry\ScrubSensitiveData`, docblock: „payload-urile `invoice.*` de la
 * Stripe cară `customer_email`"), fără nicio retenție. Fix concret propus în raport:
 * „job de retenție (ex. 90 zile) care șterge/redactează `payload` pentru rândurile
 * `processed`/`ignored` mai vechi decât pragul, păstrând coloanele de metadate deja
 * folosite de ecranul de operare".
 *
 * DE CE doar `processed`/`ignored`, NICIODATĂ `received`/`processing`/`failed`: acestea
 * din urmă sunt stări care mai pot fi REÎNCERCATE (`App\Jobs\Webhooks\ProcessStripeWebhookJob`
 * relansat manual sau redelivrat de Stripe) — `payload` e exact datele de care o reîncercare
 * are nevoie (`$event->payload` citit de job, vezi controllerul). `processed` (succes) și
 * `ignored` (semnătură validă, eveniment care nu ne privește — `WebhookHealthController`)
 * sunt stări TERMINALE: niciun cod din aplicație nu mai citește `payload` după ele. `failed`
 * e la fel de terminal ÎN COD (a treia încercare eșuată, nimic nu-l reia automat), dar
 * rămâne exclus deliberat — e exact rândul pe care un operator uman l-ar putea investiga
 * manual, redeschizând nevoia de payload-ul original; retenția lui e o decizie separată,
 * neluată încă (nu în felia acestui lot).
 *
 * DE CE fără `TenantContext::run()`, spre deosebire de `PruneSentEmailsJob`/
 * `AnonymizeActivityLogJob`: `webhook_events` NU are `tenant_id` și NU are RLS — e o coadă
 * de deduplicare CROSS-TENANT, la nivel de deployment (migrația `create_webhook_events_table`,
 * `WebhookHealthController`). Nu există „tenanți de iterat": jobul rulează o singură trecere,
 * pe conexiunea aplicației, exact ca a doua trecere (fără tenant) din `PruneSentEmailsJob`.
 *
 * DE CE `UPDATE` prin `DB::table()`, nu `WebhookEvent::query()->update()`: identic cu motivul
 * din `PruneSentEmailsJob`/`AnonymizeActivityLogJob` — un `Builder::update()` în masă nu
 * declanșează evenimente Eloquent, dar `DB::table()` evită orice ambiguitate legată de
 * cast-ul `array` al coloanei `payload` (`jsonb_build_object` scrie JSON direct, calculat de
 * PostgreSQL, dintr-un singur round-trip, ca la anonimizarea jurnalului de activitate).
 *
 * `payload` e NOT NULL (migrația), deci rândul nu poate rămâne cu `NULL` — se înlocuiește cu
 * un obiect minimal care păstrează DOAR ce era deja public prin coloanele proprii (`type`,
 * `event_id`), nu date de business: `{"redacted": true, "type": ..., "id": ...}`. `payload_hash`
 * NU se recalculează — rămâne dovada hash-ului payload-ului ORIGINAL primit de la Stripe
 * (semnătura verificată la recepție), nu o reflectare a stării curente, redactate, a rândului.
 *
 * Idempotent prin CONVERGENȚĂ (ca `AnonymizeActivityLogJob`): re-aplicarea pe un rând deja
 * redactat produce exact același obiect minimal. Clauza `whereRaw` de mai jos e doar o
 * optimizare (sare peste rândurile deja convergente), nu condiția de corectitudine.
 *
 * Prag de retenție: 90 de zile — CONSTANTĂ DE CLASĂ, nu cheie de config: `config/throughput.php`
 * nu e în felia acestui lot. Propunere pentru integrare: `throughput.limits.
 * webhook_event_payload_retention_days` (env `WEBHOOK_EVENT_PAYLOAD_RETENTION_DAYS`), alături
 * de `sent_email_retention_days`/`export_retention_days`.
 */
class RedactWebhookEventPayloadsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    private const RETENTION_DAYS = 90;

    private const CHUNK_SIZE = 500;

    /** @return list<string> */
    private static function redactableStatuses(): array
    {
        return [WebhookEvent::STATUS_PROCESSED, WebhookEvent::STATUS_IGNORED];
    }

    public function handle(): void
    {
        $cutoff = CarbonImmutable::now('UTC')->subDays(self::RETENTION_DAYS);

        DB::table('webhook_events')
            ->whereIn('status', self::redactableStatuses())
            ->where('received_at', '<', $cutoff)
            // Optimizare, nu corectitudine (vezi docblock-ul clasei): sare peste rândurile
            // deja redactate, ca o rulare zilnică repetată să nu rescrie la nesfârșit
            // aceleași rânduri terminale.
            ->whereRaw("coalesce(payload->>'redacted', 'false') <> 'true'")
            ->orderBy('id')
            ->chunkById(self::CHUNK_SIZE, function ($rows): void {
                $this->redactChunk($rows->pluck('id')->all());
            });
    }

    /** @param  list<string>  $ids */
    private function redactChunk(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        // `type`/`event_id` sunt coloane proprii ale ACELUIAȘI rând — `jsonb_build_object`
        // le poate referi direct, fără subquery, exact ca orice altă expresie SET.
        DB::table('webhook_events')
            ->whereIn('id', $ids)
            ->update([
                'payload' => DB::raw("jsonb_build_object('redacted', true, 'type', type, 'id', event_id)"),
            ]);
    }
}
