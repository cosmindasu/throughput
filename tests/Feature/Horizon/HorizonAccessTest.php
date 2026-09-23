<?php

namespace Tests\Feature\Horizon;

use App\Http\Middleware\HorizonBasicAuth;
use App\Models\User;
use Tests\TestCase;

/**
 * OPS-03 — dashboard-ul Horizon (specs.md §25.2) e accesibil proprietarului în producție, pe
 * credențiale din env, și numai lui: nici conturile demo (parole publice), nici un env fără
 * credențiale nu deschid ușa.
 */
class HorizonAccessTest extends TestCase
{
    private const USER = 'ops';

    private const PASSWORD = 'correct horse battery staple';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'horizon.basic_auth.user' => self::USER,
            'horizon.basic_auth.password' => self::PASSWORD,
        ]);
    }

    private function horizonUrl(): string
    {
        return '/'.config('horizon.path');
    }

    /**
     * @return array<string, string>
     */
    private function basicAuth(string $user, string $password): array
    {
        return ['Authorization' => 'Basic '.base64_encode($user.':'.$password)];
    }

    public function test_the_owner_opens_the_dashboard_with_the_env_credentials(): void
    {
        $this->get($this->horizonUrl(), $this->basicAuth(self::USER, self::PASSWORD))
            ->assertOk();
    }

    public function test_without_credentials_the_browser_gets_the_basic_auth_challenge(): void
    {
        $this->get($this->horizonUrl())
            ->assertUnauthorized()
            ->assertHeader('WWW-Authenticate', 'Basic realm="Horizon", charset="UTF-8"');
    }

    public function test_a_wrong_password_is_refused(): void
    {
        $this->get($this->horizonUrl(), $this->basicAuth(self::USER, 'wrong'))
            ->assertUnauthorized();
    }

    public function test_a_logged_in_demo_account_is_not_enough(): void
    {
        $this->actingAs(User::factory()->create())
            ->get($this->horizonUrl())
            ->assertUnauthorized();
    }

    public function test_the_internal_api_is_behind_the_same_door(): void
    {
        $this->getJson($this->horizonUrl().'/api/stats')->assertUnauthorized();
    }

    public function test_without_configured_credentials_nobody_gets_in(): void
    {
        config(['horizon.basic_auth.user' => null, 'horizon.basic_auth.password' => null]);

        // Nici măcar perechea „goală" — fail-closed, nu „orice trece când lipsește config-ul".
        $this->get($this->horizonUrl(), $this->basicAuth('', ''))->assertUnauthorized();
    }

    public function test_repeated_wrong_attempts_are_throttled_per_ip(): void
    {
        for ($attempt = 0; $attempt < HorizonBasicAuth::MAX_FAILED_ATTEMPTS; $attempt++) {
            $this->get($this->horizonUrl(), $this->basicAuth(self::USER, 'wrong'))->assertUnauthorized();
        }

        // Peste prag, nici parola corectă nu mai e verificată până expiră fereastra.
        $this->get($this->horizonUrl(), $this->basicAuth(self::USER, self::PASSWORD))
            ->assertTooManyRequests()
            ->assertHeader('Retry-After');
    }

    public function test_the_inline_dashboard_script_carries_the_nonce_allowed_by_the_csp(): void
    {
        $response = $this->get($this->horizonUrl(), $this->basicAuth(self::USER, self::PASSWORD))
            ->assertOk();

        $this->assertMatchesRegularExpression('/<script type="module" nonce="([^"]+)"/', $response->getContent());
        preg_match('/<script type="module" nonce="([^"]+)"/', $response->getContent(), $matches);

        $csp = (string) $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("script-src 'self' 'nonce-{$matches[1]}'", $csp);
    }
}
