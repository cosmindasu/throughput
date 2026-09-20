<?php

namespace App\Jobs\Reports;

use App\Enums\ReportFormat;
use App\Mail\ReportDeliveryMail;
use App\Models\ReportRun;
use App\Services\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Livrarea prin email a unui `report_runs` reușit (specs.md §16.2 pct. 4, ADR-009).
 * Dispecerizat DOAR de `GenerateReportJob` la succes — niciodată la eșec.
 *
 * Job de TENANT (§6.3): constructor cu scalari. Apelul extern (trimiterea efectivă,
 * Resend prin `Mail::to()->send()`) se face STRICT ÎN AFARA oricărei tranzacții deschise —
 * o singură tranzacție SCURTĂ citește datele necesare și se închide, apoi trimiterea rulează
 * fără nicio tranzacție Postgres ținută (ADR-013, §6.3 „joburile cu apeluri externe își
 * gestionează singure contextul"). Nimic de scris înapoi după trimitere (n-a existat cerere
 * de a ține un `delivered_at`), deci nu există o a doua tranzacție.
 *
 * Interceptarea listei albe de demo (§22.3, BR-DEMO-02) e complet transparentă aici —
 * mecanismul îl construiește alt lot, la nivelul transportului Symfony Mailer. Codul de
 * mai jos trimite normal, exact cum cere task-ul.
 *
 * `ReportDeliveryMail` e construit ÎNĂUNTRUL lui `TenantContext::run()` de mai jos, ÎNAINTE
 * ca funcția să returneze și contextul să se închidă (fix P3, review) — obligatoriu pentru
 * `App\Mail\Concerns\AttributesSentEmailToTenant::attributeSentEmailToCurrentTenant()`,
 * apelată din constructorul Mailable-ului, care captează tenantul cât încă e activ. Fără
 * asta, antetul `X-Throughput-Tenant-Id` ar lipsi, iar `DemoInterceptingTransport` ar
 * scrie rândul din jurnalul „Sent Emails" cu `tenant_id = null` — invizibil în Settings-ul
 * tenantului care a programat raportul.
 *
 * ADR-022, specs.md §15.8 FR-I18N-05 — `locale` e SCALAR de constructor, exact ca
 * `tenantId`/`reportRunId` (ADR-013/014), rezolvat de `App\Jobs\Reports\GenerateReportJob`
 * ÎNAINTE de dispecerizare, din `report_definitions.created_by`'s `users.locale`
 * (destinatarii sunt adrese arbitrare, fără cont — aproximarea din specificație). Worker-ul
 * de coadă e un proces de viață lungă (`.ai/rules/tenancy.md:123-138`): `App::setLocale()`
 * scrie pe singleton-ul `Translator` din container, iar Laravel resetează între joburi doar
 * instanțele `scoped()` — fără apelul explicit de mai jos, un job FR urmat de un job EN pe
 * ACELAȘI worker ar reda al doilea email tot în franceză. Vezi
 * `tests/Feature/I18n/JobLocaleLeakTest.php`, care demonstrează exact acest scenariu.
 */
class DeliverReportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(
        public string $tenantId,
        public string $reportRunId,
        public string $locale = 'en',
    ) {}

    public function handle(): void
    {
        // Vezi docblock-ul clasei — obligatoriu la ÎNCEPUTUL lui handle(), necondiționat
        // (nu doar „dacă diferă de ce e setat deja"): un worker de viață lungă nu are
        // niciun alt semnal de încredere despre ce a lăsat jobul anterior în urmă.
        App::setLocale($this->locale);

        [$mailable, $recipients] = TenantContext::run($this->tenantId, function (): array {
            $run = ReportRun::query()->with('reportDefinition')->find($this->reportRunId);

            if ($run === null || $run->status !== ReportRun::STATUS_SUCCESS || $run->file_path === null) {
                return [null, []];
            }

            $definition = $run->reportDefinition;

            if ($definition === null || $definition->recipients === []) {
                return [null, []];
            }

            $format = ReportFormat::from($definition->format);

            $mailable = new ReportDeliveryMail(
                reportName: $definition->name,
                formatLabel: $format->value,
                rowCount: (int) ($run->row_count ?? 0),
                attachmentDisk: 'local',
                attachmentPath: $run->file_path,
                attachmentName: Str::slug($definition->name).'.'.$format->extension(),
                attachmentMime: $format->mimeType(),
                locale: $this->locale,
            );

            return [$mailable, $definition->recipients];
        });

        if ($mailable === null || $recipients === []) {
            return;
        }

        Mail::to($recipients)->send($mailable);
    }

    public function failed(Throwable $e): void
    {
        // Fișierul e deja generat cu succes (altfel jobul n-ar fi fost dispecerizat) — un
        // eșec de livrare prin email nu răstoarnă `report_runs.status`, doar se raportează.
        // Istoricul rămâne „success" cu fișierul descărcabil manual din `Reports/Show.tsx`.
        report($e);
    }
}
