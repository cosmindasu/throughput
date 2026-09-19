<?php

namespace Tests\Feature\Auth;

use App\Models\Scopes\TenantScope;
use App\Models\SentEmail;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * FR-PUB-05, BR-DEMO-02, specs.md §4.5 + §22.3 — la momentul trimiterii, recuperarea
 * parolei NU are niciun context de tenant (`PasswordResetLinkController` stă pe rute
 * `guest`, în afara grupului `session.context`/`workspace` — routes/web.php). Verifică,
 * printr-o cerere HTTP REALĂ (nu unități izolate), cele două decizii ale pachetului:
 *   1. rândul din jurnal se scrie cu `tenant_id = null` (nu se pierde, dar nu aparține
 *      niciunui workspace — vezi migrația `sent_emails` pentru politica RLS completă);
 *   2. link-ul de resetare (token valid) e REDACTAT înainte de scriere, indiferent de
 *      lista albă (App\Support\Mail\SentEmailRedactor).
 */
class PasswordResetEmailInterceptionTest extends TestCase
{
    public function test_the_reset_link_is_journaled_tenant_less_and_with_the_token_redacted(): void
    {
        config(['throughput.demo.mode' => true]);
        config(['throughput.demo.email_allowlist' => []]);

        $ip = '192.0.2.'.random_int(1, 254);
        RateLimiter::clear('forgot-password:'.$ip);

        $user = User::factory()->create(['email' => 'reset-target-'.str()->random(12).'@example.test']);

        $this->post('/forgot-password', ['email' => $user->email], ['REMOTE_ADDR' => $ip])
            ->assertSessionHas('status');

        $sent = SentEmail::withoutGlobalScope(TenantScope::class)
            ->where('subject', 'like', '%Reset%')
            ->latest('created_at')
            ->firstOrFail();

        $this->assertNull($sent->tenant_id, 'Un email fără tenant (recuperare parolă) nu trebuie atribuit niciunui workspace.');
        $this->assertSame(SentEmail::STATUS_INTERCEPTED, $sent->status);
        $this->assertTrue($sent->redacted);
        $this->assertStringContainsString('[redacted-token]', (string) $sent->html_body);
        $this->assertMatchesRegularExpression('#reset-password/\[redacted-token\]#', (string) $sent->html_body);
    }
}
