<?php

namespace App\Jobs\System;

use App\Models\Contact;
use App\Services\Tenancy\TenantContext;
use App\Support\Activity\ActivityLogAnonymizer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;

/**
 * GDPR-02 (audit 2026-09-23, `docs/reviews/2026-09-23_audit/08-gdpr.md`) — plasa de siguranță
 * pentru ultimul rest al cursei asincrone de la ștergerea unui contact.
 *
 * `App\Support\Contacts\ContactErasure` mască rândurile `activity_log` care EXISTĂ în momentul
 * ștergerii, iar `App\Observers\ActivityLogObserver` mască la sursă rândul produs de ștergerea
 * însăși. Rămâne un singur caz: o modificare ANTERIOARĂ a aceluiași contact al cărei rând e încă
 * în coadă (`WriteActivityLogEntry`, nedrenat) exact când se cere ștergerea. Acel rând se scrie
 * DUPĂ tranzacția de ștergere, cu PII-ul de dinainte, și nimic nu l-ar mai atinge până la
 * retenția de 36 de luni.
 *
 * Jobul re-aplică aceeași mascare pe rândurile contactului, după commit și cu o întârziere
 * (`DELAY_SECONDS`): worker-ul e unic și FIFO (ADR-017), deci orice scriere de jurnal pusă în
 * coadă înaintea ștergerii rulează înaintea lui; întârzierea acoperă și o reîncercare a
 * listener-ului (`tries = 3`), care reintră la coada cozii. Idempotent prin convergență —
 * aceeași transformare ca `AnonymizeActivityLogJob`, deci o rulare în plus nu schimbă nimic.
 *
 * Job de TENANT (primește `tenantId` scalar, `.ai/rules/tenancy.md`), stă în `System/` lângă
 * celelalte joburi de retenție a jurnalului, cu care împarte mecanismul.
 */
class MaskErasedContactActivityLogJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const DELAY_SECONDS = 300;

    public int $tries = 3;

    public function __construct(
        public string $tenantId,
        public string $contactId,
    ) {}

    public function handle(): void
    {
        TenantContext::run($this->tenantId, function (): void {
            ActivityLogAnonymizer::anonymize(
                DB::table('activity_log')
                    ->where('auditable_type', Contact::class)
                    ->where('auditable_id', $this->contactId),
            );
        });
    }
}
