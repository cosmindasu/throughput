<?php

namespace Tests\Feature\Mail;

use App\Mail\Transport\DemoInterceptingTransport;
use App\Models\Scopes\TenantScope;
use App\Models\SentEmail;
use App\Services\Tenancy\TenantContext;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;
use Tests\TestCase;

/**
 * BR-DEMO-02, specs.md §22.3 — `App\Mail\Transport\DemoInterceptingTransport`, registrat
 * peste ORICE mailer prin `App\Mail\InterceptingMailManager` (`MAIL_MAILER=array` în teste
 * — `.env.testing`/`phpunit.xml`). `Mail::fake()` NU se folosește aici: înlocuiește
 * `mail.manager` întreg, deci ar ocoli exact mecanismul testat.
 */
class DemoInterceptingTransportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Stare de plecare curată — fiecare test își fixează explicit lista albă.
        config(['throughput.demo.mode' => true]);
        config(['throughput.demo.email_allowlist' => []]);
    }

    public function test_an_allowlisted_recipient_is_delivered_for_real_and_journaled(): void
    {
        config(['throughput.demo.email_allowlist' => ['allowed@example.com']]);

        Mail::html('<p>Body</p>', function ($message): void {
            $message->to('allowed@example.com')->subject('Weekly report');
        });

        $sent = $this->onlySentEmail();
        $this->assertSame(SentEmail::STATUS_DELIVERED, $sent->status);
        $this->assertCount(1, $sent->recipients);
        $this->assertTrue($sent->recipients[0]['allowed']);

        $messages = $this->transportMessages();
        $this->assertCount(1, $messages);
        $this->assertSame('allowed@example.com', $messages[0]->getOriginalMessage()->getTo()[0]->getAddress());
    }

    public function test_a_recipient_outside_the_allowlist_never_reaches_the_real_transport_but_is_journaled(): void
    {
        config(['throughput.demo.email_allowlist' => ['allowed@example.com']]);

        Mail::html('<p>Body</p>', function ($message): void {
            $message->to('stranger@example.org')->subject('Password reset');
        });

        $sent = $this->onlySentEmail();
        $this->assertSame(SentEmail::STATUS_INTERCEPTED, $sent->status);
        $this->assertFalse($sent->recipients[0]['allowed']);

        $this->assertCount(0, $this->transportMessages());
    }

    public function test_an_empty_allowlist_intercepts_everything_it_does_not_deliver_everything(): void
    {
        // Capcana explicită din mandat: lista goală (implicitul din .env.example) trebuie
        // să însemne „interceptează tot", NU „livrează tot".
        config(['throughput.demo.email_allowlist' => []]);

        Mail::html('<p>Body</p>', function ($message): void {
            $message->to('anyone@example.com')->subject('Anything');
        });

        $sent = $this->onlySentEmail();
        $this->assertSame(SentEmail::STATUS_INTERCEPTED, $sent->status);
        $this->assertCount(0, $this->transportMessages());
    }

    public function test_matching_is_case_insensitive_on_domain_and_on_exact_address(): void
    {
        config(['throughput.demo.email_allowlist' => ['Allowed.Domain.com', 'Exact@Example.com']]);

        Mail::html('<p>Body</p>', function ($message): void {
            $message
                ->to('SOMEONE@ALLOWED.DOMAIN.COM')
                ->cc('EXACT@EXAMPLE.COM')
                ->bcc('other@example.com');
        });

        $sent = $this->onlySentEmail();
        $byAddress = collect($sent->recipients)->keyBy('address');

        $this->assertTrue($byAddress['SOMEONE@ALLOWED.DOMAIN.COM']['allowed']);
        $this->assertTrue($byAddress['EXACT@EXAMPLE.COM']['allowed']);
        $this->assertFalse($byAddress['other@example.com']['allowed']);
        $this->assertSame(SentEmail::STATUS_PARTIAL, $sent->status);
    }

    public function test_a_mixed_message_delivers_only_to_the_allowed_recipient(): void
    {
        config(['throughput.demo.email_allowlist' => ['allowed@example.com']]);

        Mail::html('<p>Body</p>', function ($message): void {
            $message->to(['allowed@example.com', 'blocked@example.org'])->subject('Mixed');
        });

        $sent = $this->onlySentEmail();
        $this->assertSame(SentEmail::STATUS_PARTIAL, $sent->status);
        $this->assertCount(2, $sent->recipients);

        $messages = $this->transportMessages();
        $this->assertCount(1, $messages);
        $to = $messages[0]->getOriginalMessage()->getTo();
        $this->assertCount(1, $to);
        $this->assertSame('allowed@example.com', $to[0]->getAddress());
    }

    /**
     * Decizie (raportul pachetului): `DEMO_MODE=false` → transport TRANSPARENT, livrare
     * normală, FĂRĂ jurnal. Interceptarea + jurnalul sunt un guardrail de demo public
     * (§22), nu o caracteristică generală — un jurnal cu conținut complet de email pentru
     * utilizatori reali ar fi un risc nou, nejustificat.
     */
    public function test_demo_mode_false_delivers_normally_and_writes_nothing_to_the_journal(): void
    {
        config(['throughput.demo.mode' => false]);
        config(['throughput.demo.email_allowlist' => []]);

        Mail::html('<p>Body</p>', function ($message): void {
            $message->to('anyone@example.com')->subject('Anything');
        });

        $this->assertSame(0, SentEmail::withoutGlobalScope(TenantScope::class)->count());

        $messages = $this->transportMessages();
        $this->assertCount(1, $messages);
        $this->assertSame('anyone@example.com', $messages[0]->getOriginalMessage()->getTo()[0]->getAddress());
    }

    /**
     * Risc de securitate semnalat în raportul pachetului: un link de resetare a parolei
     * conține un TOKEN VALID. Apărarea aleasă: redactare necondiționată în
     * `App\Support\Mail\SentEmailRedactor`, aplicată de transport înainte de scriere —
     * niciodată conținutul brut în jurnal, indiferent de tenant/rol.
     */
    public function test_a_password_reset_link_token_is_redacted_before_it_reaches_the_journal(): void
    {
        config(['throughput.demo.email_allowlist' => []]);

        $token = str_repeat('a1B2', 20); // 80 caractere, formă plauzibilă de token Laravel.
        $html = "<p>Click <a href=\"https://demo.test/reset-password/{$token}\">here</a> to reset.</p>";

        Mail::html($html, function ($message): void {
            $message->to('someone@example.com')->subject('Reset your password');
        });

        $sent = $this->onlySentEmail();
        $this->assertTrue($sent->redacted);
        $this->assertStringNotContainsString($token, (string) $sent->html_body);
        $this->assertStringContainsString('[redacted-token]', (string) $sent->html_body);
    }

    /**
     * Un email pornit dintr-un context de tenant (rapoarte programate, invitații de membri
     * — Faza 5) se atribuie tenantului curent, prin `App\Concerns\BelongsToTenant` — sursa
     * unică de „tenant curent" din toată aplicația, nu o logică proprie a transportului.
     */
    public function test_a_tenant_scoped_send_is_attributed_to_the_current_tenant(): void
    {
        config(['throughput.demo.email_allowlist' => []]);

        $tenant = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');

        TenantContext::run($tenant, function (): void {
            Mail::html('<p>Body</p>', function ($message): void {
                $message->to('owner@example.com')->subject('Scheduled report');
            });
        });

        TenantContext::run($tenant, function () use ($tenant): void {
            $sent = SentEmail::query()->sole();
            $this->assertSame($tenant->getKey(), $sent->tenant_id);
        });
    }

    /**
     * FR-PUB-05 — recuperarea parolei pleacă de pe o rută `guest`, fără niciun context de
     * tenant. Decizie (raportul pachetului): `tenant_id` NULLABIL, ca rândul să existe în
     * jurnal (BR-DEMO-02 îl cere explicit), dar invizibil din orice workspace (vezi
     * migrația `sent_emails` pentru politica RLS completă și `SentEmailIsolationTest`).
     */
    public function test_a_send_with_no_tenant_context_is_journaled_with_a_null_tenant(): void
    {
        config(['throughput.demo.email_allowlist' => []]);

        $this->clearDatabaseTenantContext();

        Mail::html('<p>Body</p>', function ($message): void {
            $message->to('visitor@example.com')->subject('Reset your password');
        });

        $sent = SentEmail::withoutGlobalScope(TenantScope::class)->whereNull('tenant_id')->sole();
        $this->assertNull($sent->tenant_id);
    }

    /**
     * P3-7 (review general) — un `to: [a@x, a@x]` nu e o breșă, dar unele servere SMTP
     * refuză un `RCPT TO` duplicat, iar altele livrează de două ori; `Envelope::
     * setRecipients()` nu deduplică singur.
     */
    public function test_duplicate_recipients_in_the_same_field_are_deduplicated_case_insensitively(): void
    {
        config(['throughput.demo.email_allowlist' => ['dup@example.com']]);

        Mail::html('<p>Body</p>', function ($message): void {
            $message->to(['dup@example.com', 'DUP@EXAMPLE.COM', 'dup@example.com'])->subject('Duplicates');
        });

        $sent = $this->onlySentEmail();
        $this->assertCount(1, $sent->recipients, 'Duplicatele din același câmp trebuie colapsate la un singur rând.');

        $messages = $this->transportMessages();
        $this->assertCount(1, $messages);
        $this->assertCount(1, $messages[0]->getOriginalMessage()->getTo(), 'Transportul real nu trebuie să primească RCPT TO duplicat.');
    }

    /**
     * P1 (review general) — o scriere de jurnal picată NU are voie să rupă trimiterea:
     * recuperarea parolei (FR-PUB-05) trece prin acest transport SINCRON, în cererea HTTP,
     * fără niciun try/catch al ei — o excepție nescăpată aici ar întoarce 500 în loc de
     * mesajul generic. Simulăm eșecul cu un subiect peste limita coloanei (`string(500)`).
     */
    public function test_a_broken_journal_write_never_breaks_the_actual_send(): void
    {
        config(['throughput.demo.email_allowlist' => ['allowed@example.com']]);

        Mail::html('<p>Body</p>', function ($message): void {
            $message->to('allowed@example.com')->subject(str_repeat('x', 600));
        });

        $this->assertCount(1, $this->transportMessages(), 'Livrarea reală tot trebuie să se întâmple.');
        $this->assertSame(0, SentEmail::withoutGlobalScope(TenantScope::class)->count(), 'Scrierea eșuată nu lasă un rând parțial.');
    }

    /**
     * P2 (review general) — un eșec REAL de livrare (Resend jos, timeout) se jurnalizează
     * cu un status distinct (`failed`, NICIODATĂ „delivered" fals), apoi excepția originală
     * se RE-ARUNCĂ neschimbată, ca semantica de retry a jobului apelant să rămână intactă.
     */
    public function test_a_real_delivery_failure_is_journaled_as_failed_and_rethrown(): void
    {
        config(['throughput.demo.email_allowlist' => ['allowed@example.com']]);

        $failingInner = new class implements TransportInterface
        {
            public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
            {
                throw new TransportException('Resend is down');
            }

            public function __toString(): string
            {
                return 'failing';
            }
        };

        $transport = new DemoInterceptingTransport($failingInner, 'resend');

        $email = (new Email)
            ->from('noreply@throughput.dbg.ro')
            ->to('allowed@example.com')
            ->subject('Scheduled report')
            ->html('<p>Body</p>');

        try {
            $transport->send($email);
            $this->fail('Excepția transportului real trebuia re-aruncată.');
        } catch (TransportException $e) {
            $this->assertSame('Resend is down', $e->getMessage());
        }

        $sent = $this->onlySentEmail();
        $this->assertSame(SentEmail::STATUS_FAILED, $sent->status);
    }

    private function onlySentEmail(): SentEmail
    {
        return SentEmail::withoutGlobalScope(TenantScope::class)->sole();
    }

    /**
     * @return Collection<int, SentMessage>
     */
    private function transportMessages(): Collection
    {
        $transport = app('mail.manager')->mailer()->getSymfonyTransport();
        $this->assertInstanceOf(DemoInterceptingTransport::class, $transport);

        /** @var ArrayTransport $inner */
        $inner = $transport->innerTransport();

        return $inner->messages();
    }
}
