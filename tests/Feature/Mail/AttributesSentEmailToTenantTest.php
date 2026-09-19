<?php

namespace Tests\Feature\Mail;

use App\Models\Scopes\TenantScope;
use App\Models\SentEmail;
use App\Services\Tenancy\TenantContext;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\Mail;
use Tests\Fixtures\Mail\ForgetfulTenantAttributedTestMail;
use Tests\Fixtures\Mail\TenantAttributedTestMail;
use Tests\TestCase;
use Throwable;

/**
 * `App\Mail\Concerns\AttributesSentEmailToTenant` — răspunsul la P1-2 din review: atribuirea
 * de tenant a unui rând din „Sent Emails" nu trebuie să depindă de disciplina fiecărui autor
 * de `Mailable`. Verifică AMBELE căi.
 */
class AttributesSentEmailToTenantTest extends TestCase
{
    public function test_a_mailable_that_captures_the_tenant_is_attributed_even_after_the_context_closes(): void
    {
        config(['throughput.demo.email_allowlist' => ['owner@example.com']]);

        $tenant = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');

        // Exact tiparul reprodus în review: `Mailable`-ul se CONSTRUIEȘTE înăuntrul
        // contextului (trait-ul captează tenantul acolo), contextul se ÎNCHIDE, ȘI ABIA
        // APOI mesajul se trimite — ca `DeliverReportJob` (citește scurt, trimite în afara
        // oricărei tranzacții, ADR-013).
        $mailable = TenantContext::run($tenant, fn () => new TenantAttributedTestMail('Scheduled report'));

        $this->clearDatabaseTenantContext();

        Mail::to('owner@example.com')->send($mailable);

        $sent = SentEmail::withoutGlobalScope(TenantScope::class)->sole();
        $this->assertSame($tenant->getKey(), $sent->tenant_id);
    }

    public function test_forgetting_to_capture_the_tenant_is_reported_but_does_not_break_sending(): void
    {
        config(['throughput.demo.email_allowlist' => ['owner@example.com']]);

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

        Mail::to('owner@example.com')->send(new ForgetfulTenantAttributedTestMail);

        $this->assertCount(1, $reported, 'report() trebuia să anunțe garda din headers().');
        $this->assertStringContainsString('AttributesSentEmailToTenant', $reported[0]->getMessage());

        // Nici garda, nici omisiunea, nu blochează trimiterea.
        $sent = SentEmail::withoutGlobalScope(TenantScope::class)->sole();
        $this->assertNull($sent->tenant_id);
    }
}
