<?php

namespace Tests\Feature\Settings;

use App\Models\ApiToken;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Support\DemoMode;
use App\Support\Permissions;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Api\Concerns\IssuesApiTokens;
use Tests\TestCase;

/**
 * Settings → API tokens → „Revoke all" (specs.md §22.2, §7.4/FR-API-02) — decizia
 * proprietarului din 2026-09-22: rândul „Revocarea în masă a tuturor jetoanelor API",
 * lăsat deschis în §28.3, se construiește (varianta opusă recomandării „se scoate rândul").
 *
 * `DELETE /settings/api-tokens` (fără `{apiToken}`), `ApiTokenController::destroyAll()`,
 * `ApiTokenPolicy::revokeAll()`. Aceeași permisiune ca revocarea unui singur jeton
 * (`api_tokens.revoke`, Owner + Manager — vezi `ApiTokenManagementTest`), fiindcă §7.4 nu
 * are un rând separat pentru varianta în masă.
 */
class ApiTokenRevokeAllTest extends TestCase
{
    use IssuesApiTokens;

    private Tenant $tenant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        // `.env.testing` are `DEMO_MODE=true` implicit — acțiunea asta e GARDATĂ
        // (`DemoMode::GUARDED_ACTIONS['api-tokens.revoke-all']`), deci ar refuza 403 orice
        // test de mai jos care nu verifică explicit garda. Testele de DEMO_MODE de dedesubt
        // suprascriu explicit `true` pe cont propriu.
        config(['throughput.demo.mode' => false]);

        $this->tenant = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $this->owner = $this->makeMember($this->tenant, 'owner@throughput.dev', Permissions::OWNER);
    }

    private function url(): string
    {
        return '/'.$this->tenant->slug.'/settings/api-tokens';
    }

    public function test_an_owner_revokes_every_still_usable_token_of_their_workspace(): void
    {
        [$first, $plainFirst] = $this->issueTokenRow($this->tenant, $this->owner, [ApiToken::ABILITY_ORDERS_READ]);
        [$second, $plainSecond] = $this->issueTokenRow($this->tenant, $this->owner, [ApiToken::ABILITY_ORDERS_READ]);

        $this->getJson('/api/v1/orders', $this->bearer($plainFirst))->assertOk();
        $this->getJson('/api/v1/orders', $this->bearer($plainSecond))->assertOk();

        $this->actingAs($this->owner)->delete($this->url())
            ->assertRedirect($this->url())
            ->assertSessionHas('success');

        // Amândouă jetoanele cheia Sanctum a murit — nu mai autentifică.
        $this->getJson('/api/v1/orders', $this->bearer($plainFirst))->assertStatus(401);
        $this->getJson('/api/v1/orders', $this->bearer($plainSecond))->assertStatus(401);

        TenantContext::run($this->tenant, function () use ($first, $second): void {
            $this->assertNotNull(ApiToken::query()->find($first->getKey())?->revoked_at, 'Rândul rămâne, doar revocat.');
            $this->assertNotNull(ApiToken::query()->find($second->getKey())?->revoked_at);
            $this->assertSame(2, ApiToken::query()->count(), 'Rândurile NU se șterg (istoric).');
        });

        $this->actingAs($this->owner)->get($this->url())
            ->assertInertia(fn (Assert $page) => $page
                ->where('tokens.0.status', 'revoked')
                ->where('tokens.1.status', 'revoked')
            );
    }

    public function test_an_already_revoked_token_is_left_untouched(): void
    {
        [$alreadyRevoked] = $this->issueTokenRow($this->tenant, $this->owner, [ApiToken::ABILITY_ORDERS_READ]);
        [$stillActive] = $this->issueTokenRow($this->tenant, $this->owner, [ApiToken::ABILITY_ORDERS_READ]);

        $earlierRevokedAt = Carbon::parse('2026-01-01 00:00:00');
        Carbon::setTestNow($earlierRevokedAt);
        TenantContext::run($this->tenant, fn () => $alreadyRevoked->revoke());
        Carbon::setTestNow(Carbon::parse('2026-09-22 12:00:00'));

        $this->actingAs($this->owner)->delete($this->url())->assertRedirect($this->url());

        TenantContext::run($this->tenant, function () use ($alreadyRevoked, $stillActive, $earlierRevokedAt): void {
            $this->assertTrue(
                $earlierRevokedAt->equalTo(ApiToken::query()->find($alreadyRevoked->getKey())->revoked_at),
                'Un jeton deja revocat nu-și schimbă `revoked_at` — idempotență literală, nu doar fără eroare.',
            );
            $this->assertNotNull(ApiToken::query()->find($stillActive->getKey())->revoked_at);
        });

        Carbon::setTestNow();
    }

    /**
     * DOM-01 (audit 2026-09-23) — cursa, intercalată determinist. Un apel repetat la rând
     * NU o reproduce: al doilea `SELECT` vede deja `revoked_at` scris și întoarce 0 și pe
     * codul vechi. Fereastra reală e ÎNTRE `SELECT`-ul candidaților și `UPDATE`: o cerere
     * concurentă revocă jetonul exact acolo. `DB::listen` rulează după fiecare interogare,
     * pe aceeași conexiune și în aceeași tranzacție, deci scrierea din listener e vizibilă
     * `UPDATE`-ului care urmează — exact ce vede a doua tranzacție după `EvalPlanQual`.
     * Pe codul vechi: `revoked_at` suprascris cu `now()` mai târziu și „1 revocat".
     */
    public function test_a_token_revoked_concurrently_between_select_and_update_is_neither_overwritten_nor_counted(): void
    {
        [$token] = $this->issueTokenRow($this->tenant, $this->owner, [ApiToken::ABILITY_ORDERS_READ]);

        $concurrentRevokedAt = Carbon::parse('2026-01-01 10:00:00');
        $interleaved = false;

        DB::listen(function (QueryExecuted $query) use ($token, $concurrentRevokedAt, &$interleaved): void {
            if ($interleaved || ! str_starts_with(strtolower($query->sql), 'select') || ! str_contains($query->sql, 'api_tokens')) {
                return;
            }

            $interleaved = true;
            DB::table('api_tokens')->where('id', $token->getKey())->update(['revoked_at' => $concurrentRevokedAt]);
        });

        $count = TenantContext::run($this->tenant, fn (): int => ApiToken::revokeAllUsable());

        $this->assertTrue($interleaved, 'Premisa: revocarea concurentă a avut loc între SELECT și UPDATE.');
        $this->assertSame(0, $count, 'Nimic n-a fost revocat de ACEST apel — cererea concurentă a câștigat.');
        $this->assertTrue(
            $concurrentRevokedAt->equalTo($token->fresh()->revoked_at),
            '`revoked_at` scris de cererea concurentă rămâne neatins.',
        );
    }

    public function test_calling_it_twice_on_an_already_empty_workspace_does_not_explode(): void
    {
        $this->actingAs($this->owner);

        $this->delete($this->url())->assertRedirect($this->url())->assertSessionHas('success');
        $this->delete($this->url())->assertRedirect($this->url())->assertSessionHas('success');

        TenantContext::run($this->tenant, fn () => $this->assertSame(0, ApiToken::query()->count()));
    }

    public function test_it_never_touches_another_workspaces_tokens(): void
    {
        $other = $this->makeTenant('northgate', 'Northgate Electrical Distribution');
        $otherOwner = $this->makeMember($other, 'owner@northgate.test', Permissions::OWNER);
        [$otherToken, $otherPlainToken] = $this->issueTokenRow($other, $otherOwner, [ApiToken::ABILITY_ORDERS_READ]);
        $this->issueTokenRow($this->tenant, $this->owner, [ApiToken::ABILITY_ORDERS_READ]);

        $this->actingAs($this->owner)->delete($this->url())->assertRedirect($this->url());

        $this->getJson('/api/v1/orders', $this->bearer($otherPlainToken))->assertOk();

        TenantContext::run($other, fn () => $this->assertNull(
            ApiToken::query()->find($otherToken->getKey())?->revoked_at,
        ));
    }

    public function test_a_manager_may_revoke_all_too(): void
    {
        $manager = $this->makeMember($this->tenant, 'manager@throughput.dev', Permissions::MANAGER);
        $this->issueTokenRow($this->tenant, $this->owner, [ApiToken::ABILITY_ORDERS_READ]);

        $this->actingAs($manager)->delete($this->url())->assertRedirect($this->url());
    }

    public function test_an_agent_and_a_viewer_are_refused(): void
    {
        foreach ([Permissions::AGENT, Permissions::VIEWER] as $role) {
            $user = $this->makeMember($this->tenant, strtolower($role).'@throughput.dev', $role);
            [$token] = $this->issueTokenRow($this->tenant, $this->owner, [ApiToken::ABILITY_ORDERS_READ]);

            $this->actingAs($user)->delete($this->url())->assertForbidden();

            TenantContext::run($this->tenant, fn () => $this->assertNull(
                ApiToken::query()->find($token->getKey())?->revoked_at,
                "{$role} nu are voie să revoce — jetonul rămâne activ.",
            ));
        }
    }

    public function test_it_is_refused_in_demo_mode_even_for_an_owner(): void
    {
        config(['throughput.demo.mode' => true]);

        [$token, $plainToken] = $this->issueTokenRow($this->tenant, $this->owner, [ApiToken::ABILITY_ORDERS_READ]);

        $this->actingAs($this->owner)->delete($this->url())
            ->assertForbidden()
            ->assertSee(DemoMode::refusal('api-tokens.revoke-all'));

        $this->getJson('/api/v1/orders', $this->bearer($plainToken))->assertOk();

        TenantContext::run($this->tenant, fn () => $this->assertNull(
            ApiToken::query()->find($token->getKey())?->revoked_at,
        ));
    }

    public function test_the_button_is_hidden_from_the_index_in_demo_mode(): void
    {
        config(['throughput.demo.mode' => true]);

        $this->actingAs($this->owner)->get($this->url())
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Settings/ApiTokens/Index')
                ->where('can.revokeAll', false)
            );
    }

    public function test_the_button_is_available_once_demo_mode_is_off(): void
    {
        config(['throughput.demo.mode' => false]);

        $this->actingAs($this->owner)->get($this->url())
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('can.revokeAll', true));
    }

    /**
     * Revocarea unui SINGUR jeton rămâne permisă în demo (§22.2 numește doar acțiunea în
     * masă) — verificat aici cu `DELETE /settings/api-tokens/{apiToken}`, nu doar pe
     * registru, ca regresia să pice și dacă `EnsureDemoModeGuardrails` ar deveni prea
     * larg (ex. un `str_contains('api-tokens')` fără condiția „all/bulk").
     */
    public function test_revoking_a_single_token_stays_allowed_in_demo_mode(): void
    {
        config(['throughput.demo.mode' => true]);

        [$token] = $this->issueTokenRow($this->tenant, $this->owner, [ApiToken::ABILITY_ORDERS_READ]);

        $this->actingAs($this->owner)->delete($this->url().'/'.$token->getKey())
            ->assertRedirect($this->url());
    }
}
