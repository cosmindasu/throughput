<?php

namespace App\Jobs\Gdpr;

use App\Actions\Gdpr\BuildDataExportArchiveAction;
use App\Actions\Gdpr\DataExportPaths;
use App\Actions\Gdpr\DataExportSources;
use App\Mail\DataExportReadyMail;
use App\Models\DataExportRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * `finally()` al batch-ului de export GDPR — job de TENANT PROPRIU (ADR-014), nu o closure
 * care ar scrie direct: closure-urile pasate la `Bus::batch()->finally()` sunt serializate
 * ca `CallQueuedClosure`, complet în afara contextului de tenant. Exact motivarea din
 * `App\Jobs\Bulk\FinalizeBulkOperationJob`.
 *
 * Trei faze, cu munca grea ÎNTRE tranzacții (ADR-013, ADR-014 pct. 5):
 *
 *  1. tranzacție scurtă — citește cererea, workspace-ul și autorul;
 *  2. **fără nicio tranzacție** — compune `manifest.json` și comprimă arhiva (zeci de MB de
 *     I/O și compresie; ținute într-o tranzacție ar bloca o conexiune din cele 30);
 *  3. tranzacție scurtă — scrie starea terminală, `file_path` și `expires_at`, și
 *     construiește `Mailable`-ul CÂT contextul e încă viu (obligatoriu pentru
 *     `AttributesSentEmailToTenant`, altfel rândul din jurnalul „Sent Emails" ar avea
 *     `tenant_id = null`).
 *
 * Trimiterea efectivă e după faza 3, în afara oricărei tranzacții — un apel extern
 * (Resend) nu are voie să stea într-una (ADR-013). Interceptarea de demo (BR-DEMO-02) e
 * transparentă aici: se întâmplă la nivelul transportului.
 */
class FinalizeDataExportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 300;

    public function __construct(
        public string $tenantId,
        public string $dataExportRequestId,
        public ?string $batchId = null,
    ) {}

    public function handle(): void
    {
        if ($this->batchFailed()) {
            $this->markFailed('The export could not be completed. Nothing was delivered; request a new export to try again.');
            Storage::disk(DataExportPaths::DISK)->deleteDirectory(
                DataExportPaths::workFolder($this->tenantId, $this->dataExportRequestId),
            );

            return;
        }

        $context = $this->readContext();

        if ($context === null) {
            return;
        }

        try {
            $archivePath = app(BuildDataExportArchiveAction::class)->execute(
                $this->tenantId,
                $this->dataExportRequestId,
                $this->manifest($context),
            );
        } catch (Throwable $e) {
            $this->markFailed('The export could not be packaged. Nothing was delivered; request a new export to try again.');

            report($e);

            return;
        }

        [$mailable, $recipient] = $this->complete($context, $archivePath);

        if ($mailable !== null && $recipient !== null) {
            Mail::to($recipient)->send($mailable);
        }
    }

    /**
     * `null` înseamnă „batch-ul nu mai există" (`queue:prune-batches`, §13.2 pct. 8), nu
     * „a eșuat": în cazul acela se încearcă finalizarea, iar lipsa unui fișier de entitate
     * o prinde oricum arhivarea, cu eroare explicită.
     */
    private function batchFailed(): bool
    {
        if ($this->batchId === null) {
            return false;
        }

        $batch = Bus::findBatch($this->batchId);

        if ($batch === null) {
            return false;
        }

        return $batch->failedJobs > 0 || $batch->cancelled();
    }

    /**
     * @return array<string, string>|null
     */
    private function readContext(): ?array
    {
        return TenantContext::run($this->tenantId, function (): ?array {
            $export = DataExportRequest::query()->find($this->dataExportRequestId);

            if ($export === null || $export->status !== DataExportRequest::STATUS_PROCESSING) {
                return null;
            }

            $tenant = Tenant::query()->find($this->tenantId);
            $requester = User::query()->find($export->requested_by);

            if ($tenant === null) {
                return null;
            }

            return [
                'workspaceId' => $tenant->getKey(),
                'workspaceName' => $tenant->name,
                'workspaceSlug' => $tenant->slug,
                'requestedByName' => $requester?->name ?? 'A workspace owner',
                'requestedByEmail' => (string) ($requester?->email ?? ''),
                'requestedAt' => $export->requested_at?->toIso8601String() ?? now()->toIso8601String(),
                'requestedOn' => $export->requested_at?->format('Y-m-d') ?? now()->format('Y-m-d'),
            ];
        });
    }

    /**
     * `manifest.json` — descrierea schemei livrate (FR-GDPR-01). Intrările per entitate sunt
     * citite din însemnările lăsate de fiecare `ExportTenantEntityJob`; o lipsă înseamnă o
     * arhivă incompletă, deci excepție, nu o livrare tăcut trunchiată.
     *
     * @param  array<string, string>  $context
     * @return array<string, mixed>
     */
    private function manifest(array $context): array
    {
        $disk = Storage::disk(DataExportPaths::DISK);
        $entities = [];
        $rows = 0;

        foreach (DataExportSources::names() as $name) {
            $path = DataExportPaths::part($this->tenantId, $this->dataExportRequestId, "{$name}.meta.json");

            if (! $disk->exists($path)) {
                throw new RuntimeException("The export is missing the \"{$name}\" file.");
            }

            /** @var array<string, mixed>|null $entry */
            $entry = json_decode((string) $disk->get($path), true);

            if (! is_array($entry)) {
                throw new RuntimeException("The export could not read the \"{$name}\" description.");
            }

            $entities[] = $entry;
            $rows += (int) ($entry['rows'] ?? 0);
        }

        return [
            'format' => [
                'product' => config('app.name'),
                'version' => '1.0',
                'description' => 'One JSON file per entity, with relations preserved through the same identifiers the application uses. Flat entities also ship as CSV, for spreadsheets. There is no PDF in this archive: a PDF is a rendering, not a structured, machine-readable format.',
                'encoding' => 'UTF-8',
            ],
            'workspace' => [
                'id' => $context['workspaceId'],
                'name' => $context['workspaceName'],
                'slug' => $context['workspaceSlug'],
            ],
            'request' => [
                'id' => $this->dataExportRequestId,
                'requestedAt' => $context['requestedAt'],
                'requestedBy' => [
                    'name' => $context['requestedByName'],
                    'email' => $context['requestedByEmail'],
                ],
                'generatedAt' => now()->toIso8601String(),
            ],
            'totals' => [
                'entities' => count($entities),
                'rows' => $rows,
            ],
            'entities' => $entities,
            // Onestitatea listei de mai jos e parte din cerință, nu curtoazie: un răspuns la
            // o cerere de acces trebuie să spună și ce NU conține, altfel tăcerea se citește
            // ca „nu mai există nimic".
            'notIncluded' => [
                'Sign-in credentials. Passwords are stored only as irreversible hashes and are not data anyone can be handed back.',
                'The workspace subscription and its payment methods. Those are held by the payment processor, not in this application, and are billing data of the workspace rather than records it keeps about its customers.',
                'Shipping carrier credentials. They are secrets of the workspace, stored encrypted, and exporting them would be a security hole rather than a portability feature.',
                'Uploaded import files and generated PDFs. Both are renderings or sources of the rows already in this archive, and both are deleted on their own retention schedule.',
            ],
        ];
    }

    /**
     * @param  array<string, string>  $context
     * @return array{0: DataExportReadyMail|null, 1: string|null}
     */
    private function complete(array $context, string $archivePath): array
    {
        return TenantContext::run($this->tenantId, function () use ($context, $archivePath): array {
            $export = DataExportRequest::query()->find($this->dataExportRequestId);

            if ($export === null) {
                return [null, null];
            }

            $expiresAt = now()->addDays((int) config('throughput.limits.export_retention_days'));

            $export->update([
                'status' => DataExportRequest::STATUS_COMPLETED,
                'file_path' => $archivePath,
                'expires_at' => $expiresAt,
                'completed_at' => now(),
                'error_message' => null,
            ]);

            $recipient = $context['requestedByEmail'] !== '' ? $context['requestedByEmail'] : null;

            if ($recipient === null) {
                return [null, null];
            }

            // Construit AICI, în context (fix P3 din Faza 4): `AttributesSentEmailToTenant`
            // captează tenantul la construcție, nu la trimitere — trimiterea se întâmplă
            // deja în afara oricărei tranzacții.
            $mailable = new DataExportReadyMail(
                workspaceName: $context['workspaceName'],
                requestedByName: $context['requestedByName'],
                downloadUrl: route('settings.data-export.download', [
                    'workspace' => $context['workspaceSlug'],
                    'dataExportRequest' => $this->dataExportRequestId,
                ]),
                expiresOn: $expiresAt->toFormattedDateString(),
                retentionDays: (int) config('throughput.limits.export_retention_days'),
            );

            return [$mailable, $recipient];
        });
    }

    private function markFailed(string $message): void
    {
        TenantContext::run($this->tenantId, function () use ($message): void {
            $export = DataExportRequest::query()->find($this->dataExportRequestId);

            if ($export === null || $export->status === DataExportRequest::STATUS_COMPLETED) {
                return;
            }

            $export->update([
                'status' => DataExportRequest::STATUS_FAILED,
                // Un mesaj specific, scris de `ExportTenantEntityJob::failed()` (CARE
                // entitate a căzut), nu se înlocuiește cu unul generic.
                'error_message' => $export->error_message ?? $message,
                'completed_at' => now(),
            ]);
        });
    }

    public function failed(Throwable $e): void
    {
        $this->markFailed('The export could not be completed. Nothing was delivered; request a new export to try again.');

        report($e);
    }
}
