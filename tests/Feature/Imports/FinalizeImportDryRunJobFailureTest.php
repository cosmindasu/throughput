<?php

namespace Tests\Feature\Imports;

use App\Jobs\Imports\FinalizeImportDryRunJob;
use App\Models\Import;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Support\Permissions;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;
use Throwable;

/**
 * TEST-12 (audit 2026-09-23, §11-teste.md) — `FinalizeImportDryRunJob::failed()` (~liniile
 * 58-66) n-avea niciun test: ultimul pas al probei uscate, dispecerizat de
 * `RunDryRunValidationJob` după ultimul chunk — dacă `ImportDryRunFinalizer::run()` explodează
 * (ex: eroare de conexiune la penultima interogare), importul trebuie să iasă din
 * `STATUS_VALIDATING` în `STATUS_FAILED`, nu să rămână blocat la infinit (plasa de sistem
 * `FailStuckImportsJob` există EXACT pentru cazul în care asta n-ar funcționa).
 *
 * Simetric cu `FailStuckImportsJobTest` (efectul asupra rândului) + tiparul din
 * `AttributesSentEmailToTenantTest` (fake pe `ExceptionHandler`, ca `report($e)` să fie
 * verificabil fără să atingă Sentry real).
 */
class FinalizeImportDryRunJobFailureTest extends TestCase
{
    private Tenant $marlin;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->marlin = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $this->owner = $this->makeMember($this->marlin, 'demo.owner@throughput.dev', Permissions::OWNER);

        $this->clearDatabaseTenantContext();
    }

    /**
     * Efectul real: statusul trece la `failed` și `completed_at` se populează —
     * `PruneExpiredImportFilesJob` filtrează pe `completed_at`, deci un import eșuat fără el
     * n-ar intra niciodată în retenția fișierului stocat (același motiv ca la
     * `CommitImportJob::failed()`/`RunDryRunValidationJob::failed()`, docblock-ul lor).
     *
     * Contextul de tenant e golit ÎNAINTE de a apela `failed()` — exact ce se întâmplă în
     * producție: workerul de coadă deserializează jobul FĂRĂ contextul cererii care l-a
     * dispecerizat (.ai/rules/tenancy.md, „Două familii de joburi"), deci `failed()` trebuie
     * să-și restaureze singur contextul din `$this->tenantId`, nu să se bazeze pe unul ambiant.
     */
    public function test_failed_marks_the_import_as_failed_and_stamps_completed_at(): void
    {
        $import = $this->makeValidatingImport();
        $this->clearDatabaseTenantContext();

        $job = new FinalizeImportDryRunJob($this->marlin->getKey(), $import->getKey());
        $job->failed(new RuntimeException('ImportDryRunFinalizer explodat pe ultimul chunk'));

        $fresh = TenantContext::run($this->marlin, fn () => $import->fresh());
        $this->assertSame(Import::STATUS_FAILED, $fresh->status);
        $this->assertNotNull($fresh->completed_at);
    }

    /** Aceeași excepție ajunge la handler-ul de raportare — nu se pierde tăcut. */
    public function test_failed_reports_the_exception(): void
    {
        $import = $this->makeValidatingImport();
        $this->clearDatabaseTenantContext();

        $reported = [];
        $this->app->instance(ExceptionHandler::class, new class($reported) implements ExceptionHandler
        {
            private array $reported;

            public function __construct(array &$reported)
            {
                $this->reported = &$reported;
            }

            public function report(Throwable $e)
            {
                $this->reported[] = $e;
            }

            public function shouldReport(Throwable $e)
            {
                return true;
            }

            public function render($request, Throwable $e) {}

            public function renderForConsole($output, Throwable $e) {}
        });

        $exception = new RuntimeException('ImportDryRunFinalizer explodat pe ultimul chunk');
        (new FinalizeImportDryRunJob($this->marlin->getKey(), $import->getKey()))->failed($exception);

        $this->assertCount(1, $reported, 'failed() trebuia să raporteze excepția prin report().');
        $this->assertSame($exception, $reported[0]);
    }

    /**
     * Un import dintr-un tenant deja șters/inexistent (`TenantContext::run` fără rând în
     * `tenants`) nu trebuie să arunce o excepție NOUĂ din interiorul lui `failed()` — ar
     * înlocui excepția originală în log cu una despre `tenants` lipsă, exact genul de eroare
     * derutantă documentată în `.ai/rules/tenancy.md` (context de tenant setat greșit).
     * `TenantContext::run()` verificat direct: nu cere ca rândul `tenants` să existe încă,
     * doar setează `app.tenant_id`, deci `failed()` rămâne sigur chiar pe un id arbitrar.
     */
    public function test_failed_does_not_throw_for_an_unknown_tenant_id(): void
    {
        $job = new FinalizeImportDryRunJob((string) Str::ulid(), (string) Str::ulid());

        $job->failed(new RuntimeException('boom'));

        $this->addToAssertionCount(1);
    }

    private function makeValidatingImport(): Import
    {
        return TenantContext::run($this->marlin, function (): Import {
            $import = new Import([
                'resource_type' => 'products',
                'original_filename' => 'products.csv',
                'status' => Import::STATUS_VALIDATING,
            ]);
            $import->created_by = $this->owner->getKey();
            $import->save();

            return $import;
        });
    }
}
