<?php

namespace Tests\Feature\Members;

use App\Mail\Transport\DemoInterceptingTransport;
use App\Models\ActivityLog;
use App\Models\Membership;
use App\Models\Scopes\TenantScope;
use App\Models\SentEmail;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Support\Permissions;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\Mailer\SentMessage;
use Tests\TestCase;

/**
 * US-TEN-01, §6.4 — invitarea unui membru: „se creează un membership cu status «pending» și
 * un token de acceptare" + „colegul primește un email cu link de acceptare valid 7 zile" +
 * „în mediul de demo public, email-ul nu se livrează efectiv către adrese din afara listei
 * albe (§22.3) — apare în schimb în jurnalul «Sent Emails»".
 *
 * Emailul se verifică prin MECANISMUL REAL (`DemoInterceptingTransport` peste
 * `MAIL_MAILER=array`), nu prin `Mail::fake()`: `fake()` înlocuiește `mail.manager` întreg,
 * deci ar ocoli exact interceptorul construit în Faza 4 pentru acest flux — aceeași decizie,
 * din aceleași motive, ca în `DemoInterceptingTransportTest`.
 */
class MemberInvitationTest extends TestCase
{
    private Tenant $marlin;

    private User $owner;

    private User $manager;

    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();

        // Ruta PUBLICĂ de acceptare trăiește în `routes/web/invitations.php`, un fișier nou
        // care are nevoie de un `require` în `routes/web.php` — fișier de INTEGRARE al
        // coordonatorului, pe care acest lot nu-l atinge (vezi raportul). Garda de mai jos
        // o înregistrează doar cât timp acel `require` lipsește și devine inertă în clipa
        // în care apare — testul rămâne valabil în ambele stări ale worktree-ului.
        if (! Route::has('invitations.accept')) {
            Route::middleware('web')->group(base_path('routes/web/invitations.php'));
        }

        config(['throughput.demo.mode' => false]);
        config(['throughput.demo.email_allowlist' => []]);

        $this->marlin = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $this->owner = $this->makeMember($this->marlin, 'owner@throughput.dev', Permissions::OWNER);
        $this->manager = $this->makeMember($this->marlin, 'manager@throughput.dev', Permissions::MANAGER);
        $this->agent = $this->makeMember($this->marlin, 'agent@throughput.dev', Permissions::AGENT);

        $this->clearDatabaseTenantContext();
    }

    public function test_an_owner_invites_a_colleague_and_a_pending_membership_with_a_token_is_created(): void
    {
        $this->actingAs($this->owner)
            ->post('/marlin/settings/members/invite', ['email' => 'coleg@exemplu.com', 'role' => Permissions::AGENT])
            ->assertRedirect('/marlin/settings/members')
            ->assertSessionHas('success', fn (string $message) => str_contains($message, 'coleg@exemplu.com'));

        $membership = $this->invitationFor('coleg@exemplu.com');

        $this->assertSame(Membership::STATUS_PENDING, $membership->status);
        $this->assertNotNull($membership->invitation_token);
        // Gherkin US-TEN-01 — „valid 7 zile".
        $this->assertSame(7, (int) round(now()->diffInDays($membership->invitation_expires_at, absolute: true)));

        // Tokenul e STOCAT ca hash (sha256, 64 de caractere hex) — valoarea în clar din link
        // NU se regăsește niciodată în bază.
        $this->assertSame(64, strlen((string) $membership->invitation_token));
        $this->assertNotSame($this->tokenFromLastEmail(), $membership->invitation_token);
        $this->assertSame(
            hash('sha256', $this->tokenFromLastEmail()),
            $membership->invitation_token,
        );

        $this->assertSame(Permissions::AGENT, $this->roleOf((string) $membership->user_id));
    }

    public function test_the_invitation_email_is_intercepted_and_journaled_in_demo_mode(): void
    {
        config(['throughput.demo.mode' => true]);

        $this->actingAs($this->owner)
            ->post('/marlin/settings/members/invite', ['email' => 'stranger@exemplu.com', 'role' => Permissions::VIEWER])
            ->assertRedirect('/marlin/settings/members');

        $journaled = $this->onlySentEmail();

        // BR-DEMO-02 — „nu se livrează efectiv (…) se înregistrează într-un jurnal «Sent
        // Emails», cu conținutul complet, dar fără trimitere SMTP reală".
        $this->assertSame(SentEmail::STATUS_INTERCEPTED, $journaled->status);
        $this->assertSame('stranger@exemplu.com', $journaled->recipients[0]['address']);
        $this->assertFalse($journaled->recipients[0]['allowed']);
        $this->assertCount(0, $this->transportMessages(), 'Niciun mesaj nu are voie să ajungă la transportul real.');

        // Atribuit tenantului care a trimis invitația (`AttributesSentEmailToTenant`) —
        // altfel rândul ar fi invizibil pe ecranul „Sent Emails" al acelui workspace.
        $this->assertSame($this->marlin->getKey(), $journaled->tenant_id);
    }

    public function test_an_allowlisted_invitation_is_delivered_for_real_with_the_accept_link_intact(): void
    {
        config(['throughput.demo.mode' => true]);
        config(['throughput.demo.email_allowlist' => ['exemplu.com']]);

        $this->actingAs($this->owner)
            ->post('/marlin/settings/members/invite', ['email' => 'coleg@exemplu.com', 'role' => Permissions::AGENT]);

        $messages = $this->transportMessages();
        $this->assertCount(1, $messages);

        $body = (string) $messages[0]->getOriginalMessage()->getHtmlBody();
        $this->assertMatchesRegularExpression('#/invitations/marlin/[0-9a-f]{64}#', $body);

        // …dar jurnalul NU păstrează tokenul: `SentEmailRedactor` îl scoate, ca pe orice
        // link cu putere de acces (regula de proces din docblock-ul acelei clase).
        $journaled = $this->onlySentEmail();
        $this->assertStringContainsString('/invitations/marlin/[redacted-token]', (string) $journaled->html_body);
        $this->assertTrue((bool) $journaled->redacted);
    }

    public function test_the_activity_log_records_the_invitation(): void
    {
        $this->actingAs($this->owner)
            ->post('/marlin/settings/members/invite', ['email' => 'coleg@exemplu.com', 'role' => Permissions::AGENT]);

        $log = TenantContext::run($this->marlin, fn () => ActivityLog::query()
            ->where('auditable_type', Membership::class)
            ->sole());

        $this->assertSame('created', $log->action);
        $this->assertSame('coleg@exemplu.com', $log->new_values['email']);
        $this->assertSame(Permissions::AGENT, $log->new_values['role']);
        $this->assertSame($this->owner->getKey(), $log->user_id);
    }

    /** BR-TEN-02 — „doar Owner și Manager pot invita membri". */
    public function test_an_agent_cannot_invite(): void
    {
        $this->actingAs($this->agent)
            ->from('/marlin/settings/members')
            ->post('/marlin/settings/members/invite', ['email' => 'coleg@exemplu.com', 'role' => Permissions::AGENT])
            ->assertSessionHasErrors(['role' => 'You cannot invite members to this workspace.']);

        $this->assertNull($this->invitationFor('coleg@exemplu.com', orFail: false));
    }

    /**
     * BR-TEN-02, nota ¹ de la matricea §7.4 — „Manager (…) nu poate promova pe cineva la
     * Owner". O invitație CU rolul Owner e aceeași promovare, făcută mai devreme.
     */
    public function test_a_manager_cannot_invite_an_owner(): void
    {
        $this->actingAs($this->manager)
            ->from('/marlin/settings/members')
            ->post('/marlin/settings/members/invite', ['email' => 'coleg@exemplu.com', 'role' => Permissions::OWNER])
            ->assertSessionHasErrors(['role' => 'Only an Owner can invite another Owner.']);

        $this->assertNull($this->invitationFor('coleg@exemplu.com', orFail: false));
    }

    public function test_a_manager_can_invite_an_agent(): void
    {
        $this->actingAs($this->manager)
            ->post('/marlin/settings/members/invite', ['email' => 'coleg@exemplu.com', 'role' => Permissions::AGENT])
            ->assertRedirect('/marlin/settings/members');

        $this->assertSame(Membership::STATUS_PENDING, $this->invitationFor('coleg@exemplu.com')->status);
    }

    /** Gherkin US-TEN-01 — „acțiunea nu e vizibilă în meniu (nu doar interzisă la submit)". */
    public function test_the_invite_affordance_is_absent_for_roles_without_the_permission(): void
    {
        $this->actingAs($this->agent)
            ->get('/marlin/settings/members')
            // §7.4 — Agentul are „—" pe rândul „Membri și roluri": nu vede nici lista.
            ->assertForbidden();

        $this->actingAs($this->manager)
            ->get('/marlin/settings/members')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Settings/Members/Index')
                ->where('can.invite', true)
                // BR-TEN-02 — „Owner" nici nu apare ca opțiune pentru un Manager.
                ->where('invitableRoles', [Permissions::MANAGER, Permissions::AGENT, Permissions::VIEWER])
            );
    }

    public function test_inviting_someone_who_is_already_an_active_member_is_refused(): void
    {
        $this->actingAs($this->owner)
            ->from('/marlin/settings/members')
            ->post('/marlin/settings/members/invite', ['email' => 'agent@throughput.dev', 'role' => Permissions::VIEWER])
            ->assertSessionHasErrors(['email' => 'That person is already a member of this workspace.']);
    }

    public function test_a_second_invitation_to_a_pending_address_is_refused_with_a_pointer_to_resend(): void
    {
        $this->actingAs($this->owner)
            ->post('/marlin/settings/members/invite', ['email' => 'coleg@exemplu.com', 'role' => Permissions::AGENT]);

        $this->actingAs($this->owner)
            ->from('/marlin/settings/members')
            ->post('/marlin/settings/members/invite', ['email' => 'coleg@exemplu.com', 'role' => Permissions::VIEWER])
            ->assertSessionHasErrors('email');

        $this->assertSame(1, TenantContext::run($this->marlin, fn () => Membership::query()
            ->where('status', Membership::STATUS_PENDING)
            ->count()));
    }

    /**
     * Adresa se normalizează ÎNAINTE de validare: `users.email` e unic și sensibil la
     * majuscule în PostgreSQL, deci fără normalizare „Agent@Throughput.dev" ar fi creat o
     * A DOUA identitate globală pentru aceeași persoană, trecând pe lângă verificarea „e
     * deja membru".
     */
    public function test_the_invited_address_is_normalised_before_the_already_a_member_check(): void
    {
        $this->actingAs($this->owner)
            ->from('/marlin/settings/members')
            ->post('/marlin/settings/members/invite', ['email' => 'Agent@Throughput.DEV', 'role' => Permissions::VIEWER])
            ->assertSessionHasErrors(['email' => 'That person is already a member of this workspace.']);

        $this->assertSame(1, User::query()->where('email', 'agent@throughput.dev')->count());
    }

    /** BR-TEN-04 e despre MEMBRI; o invitație neacceptată n-are istoric de păstrat. */
    public function test_revoking_a_pending_invitation_deletes_the_row_and_its_role(): void
    {
        $this->actingAs($this->owner)
            ->post('/marlin/settings/members/invite', ['email' => 'coleg@exemplu.com', 'role' => Permissions::AGENT]);

        $invitation = $this->invitationFor('coleg@exemplu.com');
        $invitedUserId = $invitation->user_id;

        $this->actingAs($this->owner)
            ->delete("/marlin/settings/members/{$invitation->getKey()}")
            ->assertRedirect('/marlin/settings/members');

        $this->assertNull($this->invitationFor('coleg@exemplu.com', orFail: false));

        $this->assertNull($this->roleOf((string) $invitedUserId), 'Un invitat revocat nu are voie să rămână cu rol în workspace.');

        $log = TenantContext::run($this->marlin, fn () => ActivityLog::query()
            ->where('auditable_type', Membership::class)
            ->where('action', 'deleted')
            ->sole());
        $this->assertSame('coleg@exemplu.com', $log->old_values['email']);
    }

    public function test_resending_an_invitation_issues_a_new_token_and_invalidates_the_previous_link(): void
    {
        $this->actingAs($this->owner)
            ->post('/marlin/settings/members/invite', ['email' => 'coleg@exemplu.com', 'role' => Permissions::AGENT]);

        $firstToken = $this->tokenFromLastEmail();
        $invitation = $this->invitationFor('coleg@exemplu.com');

        TenantContext::run($this->marlin, fn () => SentEmail::withoutGlobalScope(TenantScope::class)->delete());
        $this->clearDatabaseTenantContext();

        $this->actingAs($this->owner)
            ->post("/marlin/settings/members/{$invitation->getKey()}/resend")
            ->assertRedirect('/marlin/settings/members');

        $secondToken = $this->tokenFromLastEmail();
        $this->assertNotSame($firstToken, $secondToken);
        $this->actAsAnonymousVisitor();

        $this->get("/invitations/marlin/{$firstToken}")->assertNotFound();
        $this->get("/invitations/marlin/{$secondToken}")->assertOk();
    }

    public function test_a_new_user_accepts_the_invitation_sets_a_password_and_lands_in_the_workspace(): void
    {
        $this->actingAs($this->owner)
            ->post('/marlin/settings/members/invite', ['email' => 'coleg@exemplu.com', 'role' => Permissions::AGENT]);

        $token = $this->tokenFromLastEmail();
        $this->actAsAnonymousVisitor();

        $this->get("/invitations/marlin/{$token}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Invitations/Accept')
                ->where('workspaceName', 'Marlin Fasteners & Supply Co.')
                ->where('email', 'coleg@exemplu.com')
                ->where('roleName', Permissions::AGENT)
                ->where('needsProfile', true)
                ->where('expired', false)
            );

        $this->post("/invitations/marlin/{$token}", [
            'name' => 'Coleg Nou',
            'password' => 'a-very-long-password',
            'password_confirmation' => 'a-very-long-password',
        ])->assertRedirect('/marlin/dashboard');

        $membership = $this->invitationFor('coleg@exemplu.com', pendingOnly: false);
        $this->assertSame(Membership::STATUS_ACTIVE, $membership->status);
        $this->assertNull($membership->invitation_token, 'Tokenul trebuie CONSUMAT la acceptare.');

        $user = User::query()->where('email', 'coleg@exemplu.com')->sole();
        $this->assertSame('Coleg Nou', $user->name);
        $this->assertNotNull($user->email_verified_at);

        // Autentificat pe loc și cu acces real la workspace.
        $this->assertAuthenticatedAs($user);
        $this->get('/marlin/dashboard')->assertOk();

        // Al doilea click pe acelaşi link nu mai găsește nimic.
        $this->get("/invitations/marlin/{$token}")->assertNotFound();
    }

    /**
     * BR-TEN-04 — „un utilizator dezactivat într-un tenant poate rămâne activ în altul":
     * invitația unui utilizator EXISTENT nu-i atinge identitatea globală, doar adaugă un
     * workspace în comutator.
     */
    public function test_an_existing_user_accepts_without_being_asked_for_a_password(): void
    {
        $cascade = $this->makeTenant('cascade', 'Cascade Hydraulic Components');
        $existing = $this->makeMember($cascade, 'veteran@throughput.dev', Permissions::MANAGER);
        $existing->forceFill(['email_verified_at' => now()])->save();
        $originalName = $existing->name;
        $this->clearDatabaseTenantContext();

        $this->actingAs($this->owner)
            ->post('/marlin/settings/members/invite', ['email' => 'veteran@throughput.dev', 'role' => Permissions::VIEWER]);

        $token = $this->tokenFromLastEmail();
        $this->actAsAnonymousVisitor();

        $this->get("/invitations/marlin/{$token}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('needsProfile', false));

        $this->post("/invitations/marlin/{$token}")->assertRedirect('/marlin/dashboard');

        $this->assertSame($originalName, $existing->fresh()->name, 'Identitatea globală nu se rescrie la acceptare.');
        $this->assertSame(Membership::STATUS_ACTIVE, $this->invitationFor('veteran@throughput.dev', pendingOnly: false)->status);

        // Vechiul workspace rămâne neatins.
        $this->actingAs($existing)->get('/cascade/dashboard')->assertOk();
    }

    public function test_an_expired_invitation_explains_itself_instead_of_404ing(): void
    {
        $this->actingAs($this->owner)
            ->post('/marlin/settings/members/invite', ['email' => 'coleg@exemplu.com', 'role' => Permissions::AGENT]);

        $token = $this->tokenFromLastEmail();

        TenantContext::run($this->marlin, fn () => Membership::query()
            ->where('status', Membership::STATUS_PENDING)
            ->update(['invitation_expires_at' => now()->subDay()]));
        $this->clearDatabaseTenantContext();
        $this->actAsAnonymousVisitor();

        $this->get("/invitations/marlin/{$token}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Invitations/Accept')
                ->where('expired', true)
            );

        $this->post("/invitations/marlin/{$token}", [
            'name' => 'Coleg Nou',
            'password' => 'a-very-long-password',
            'password_confirmation' => 'a-very-long-password',
        ])->assertSessionHasErrors('token');

        $this->assertSame(Membership::STATUS_PENDING, $this->invitationFor('coleg@exemplu.com')->status);
        $this->assertGuest();
    }

    /**
     * Cazul REAL din demo-ul public: vizitatorul e deja logat ca Owner când deschide linkul
     * de acceptare. Fără golirea sesiunii în `AcceptInvitationController`, `password_hash_web`
     * al Owner-ului ar fi supraviețuit lui `regenerate()`, iar `AuthenticateSession` l-ar fi
     * delogat pe invitat la PRIMA cerere de după redirect — un „welcome" urmat instant de
     * `/login`. Regresia e prinsă aici, nu la review.
     */
    public function test_accepting_while_logged_in_as_someone_else_replaces_the_session_cleanly(): void
    {
        $this->actingAs($this->owner)
            ->post('/marlin/settings/members/invite', ['email' => 'coleg@exemplu.com', 'role' => Permissions::AGENT]);

        $token = $this->tokenFromLastEmail();

        // NU `actAsAnonymousVisitor()` — deliberat: rămânem autentificați ca Owner.
        $this->post("/invitations/marlin/{$token}", [
            'name' => 'Coleg Nou',
            'password' => 'a-very-long-password',
            'password_confirmation' => 'a-very-long-password',
        ])->assertRedirect('/marlin/dashboard');

        $invited = User::query()->where('email', 'coleg@exemplu.com')->sole();
        $this->assertAuthenticatedAs($invited);

        // Cererea DE DUPĂ redirect e cea care conta: cu sesiunea veche, aici venea 302.
        $this->get('/marlin/dashboard')->assertOk();
    }

    /**
     * Tokenul unui workspace nu deschide altul: slug-ul din cale doar RESTRÂNGE căutarea,
     * nu autorizează nimic (`App\Actions\Members\PendingInvitation`).
     */
    public function test_a_token_presented_under_another_workspace_slug_is_a_404(): void
    {
        $this->makeTenant('cascade', 'Cascade Hydraulic Components');

        $this->actingAs($this->owner)
            ->post('/marlin/settings/members/invite', ['email' => 'coleg@exemplu.com', 'role' => Permissions::AGENT]);

        $token = $this->tokenFromLastEmail();

        $this->get("/invitations/cascade/{$token}")->assertNotFound();
        $this->get('/invitations/marlin/'.str_repeat('f', 64))->assertNotFound();
        $this->get('/invitations/marlin/not-a-token')->assertNotFound();
    }

    /**
     * §18.5 — un `{membership}` dintr-un ALT tenant nu trebuie nici măcar confirmat că
     * există, nici pentru retrimitere, nici pentru revocare.
     */
    public function test_managing_an_invitation_from_another_tenant_is_a_404(): void
    {
        $cascade = $this->makeTenant('cascade', 'Cascade Hydraulic Components');
        $foreigner = $this->makeMember($cascade, 'foreigner@throughput.dev', Permissions::AGENT);
        $foreignId = TenantContext::run($cascade, fn () => Membership::query()
            ->where('user_id', $foreigner->getKey())->sole()->getKey());
        $this->clearDatabaseTenantContext();

        $this->actingAs($this->owner)->post("/marlin/settings/members/{$foreignId}/resend")->assertNotFound();
        $this->actingAs($this->owner)->delete("/marlin/settings/members/{$foreignId}")->assertNotFound();
    }

    /**
     * Re-invitarea cuiva dezactivat refolosește rândul (`unique(tenant_id, user_id)` face
     * imposibil un al doilea) și îi curăță urmele de dezactivare, ca lista să nu arate
     * simultan „invited" și o dată de dezactivare.
     */
    public function test_a_deactivated_member_can_be_invited_back_on_the_same_row(): void
    {
        $jane = $this->makeMember($this->marlin, 'jane@throughput.dev', Permissions::AGENT);
        $janeMembershipId = TenantContext::run($this->marlin, fn () => Membership::query()
            ->where('user_id', $jane->getKey())->sole()->getKey());
        $this->clearDatabaseTenantContext();

        $this->actingAs($this->owner)
            ->post("/marlin/settings/members/{$janeMembershipId}/deactivate", ['reassign' => false])
            ->assertRedirect('/marlin/settings/members');

        $this->actingAs($this->owner)
            ->post('/marlin/settings/members/invite', ['email' => 'jane@throughput.dev', 'role' => Permissions::VIEWER])
            ->assertRedirect('/marlin/settings/members');

        $membership = $this->invitationFor('jane@throughput.dev');
        $this->assertSame($janeMembershipId, $membership->getKey(), 'Același rând, nu unul nou.');
        $this->assertNull($membership->deactivated_at);
        $this->assertNull($membership->deactivated_by);
    }

    /**
     * Invitatul deschide linkul din ALT browser, fără sesiune — cazul normal. `actingAs()`
     * din Laravel ține utilizatorul pe instanța de guard pentru tot testul, deci fără pasul
     * ăsta cererile „publice" de mai jos ar fi rulat tot ca Owner-ul care a invitat.
     */
    private function actAsAnonymousVisitor(): void
    {
        $this->app['auth']->guard('web')->logout();
        $this->flushSession();
    }

    private function invitationFor(string $email, bool $orFail = true, bool $pendingOnly = true): ?Membership
    {
        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            if ($orFail) {
                $this->fail("Niciun utilizator pentru {$email}.");
            }

            return null;
        }

        $membership = TenantContext::run($this->marlin, function () use ($user, $pendingOnly): ?Membership {
            $query = Membership::query()->with('user')->where('user_id', $user->getKey());

            if ($pendingOnly) {
                $query->where('status', Membership::STATUS_PENDING);
            }

            return $query->first();
        });

        $this->clearDatabaseTenantContext();

        if ($membership === null && $orFail) {
            $this->fail("Niciun membership pentru {$email}.");
        }

        return $membership;
    }

    /**
     * Tokenul în clar există O SINGURĂ DATĂ, în corpul emailului REAL — nu în bază (unde e
     * hash-uit) și nu în jurnal (unde e redactat). Testul îl citește exact de acolo, adică
     * exact de unde îl citește și un invitat.
     */
    private function tokenFromLastEmail(): string
    {
        $messages = $this->transportMessages();
        $this->assertNotEmpty($messages, 'Niciun email nu a ajuns la transportul real.');

        $body = (string) $messages->last()->getOriginalMessage()->getHtmlBody();

        $this->assertMatchesRegularExpression('#/invitations/[a-z0-9-]+/([0-9a-f]{64})#', $body, $body);
        preg_match('#/invitations/[a-z0-9-]+/([0-9a-f]{64})#', $body, $matches);

        return $matches[1];
    }

    /** Rolul (team-scoped, §7.2) al unui utilizator în tenantul acestui test. */
    private function roleOf(string $userId): ?string
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId($this->marlin->getKey());

        $role = User::query()->findOrFail($userId)->fresh()->getRoleNames()->first();

        $registrar->setPermissionsTeamId(null);

        return $role;
    }

    /**
     * Citit ÎN contextul tenantului, nu doar fără global scope: `sent_emails` are politica
     * RLS proprie (ADR-020), iar `deliverQueuedMail()` golește `app.tenant_id` înainte de
     * drenare — fără `TenantContext::run()`, rândul jurnalizat e invizibil la nivel de bază,
     * nu absent. Capcană plătită aici: al DOILEA apel de drenare din același test ștergea
     * contextul pe care primul îl lăsase în urmă (sub tranzacția de test, `set_config(...,
     * true)` supraviețuiește commit-ului — vezi `TestCase::clearDatabaseTenantContext()`).
     */
    private function onlySentEmail(): SentEmail
    {
        $this->deliverQueuedMail();

        return TenantContext::run(
            $this->marlin,
            fn () => SentEmail::withoutGlobalScope(TenantScope::class)->sole(),
        );
    }

    /**
     * @return Collection<int, SentMessage>
     */
    private function transportMessages(): Collection
    {
        $this->deliverQueuedMail();

        $transport = app('mail.manager')->mailer()->getSymfonyTransport();
        $this->assertInstanceOf(DemoInterceptingTransport::class, $transport);

        /** @var ArrayTransport $inner */
        $inner = $transport->innerTransport();

        return $inner->messages();
    }

    /**
     * ADR-013 — invitația se trimite prin COADĂ (`Mail::to()->queue()`), fiindcă cererea
     * HTTP rulează integral într-o tranzacție deschisă. Deci nimic nu ajunge la transport
     * până nu drenăm coada, exact ca în producție; suita rulează pe coadă `database`, nu
     * `sync` (`phpunit.xml`), tocmai ca diferența asta să nu fie invizibilă.
     *
     * Idempotent: apelat de două ori, al doilea nu găsește niciun job.
     */
    private function deliverQueuedMail(): void
    {
        $this->clearDatabaseTenantContext();

        $this->artisan('queue:work', [
            '--stop-when-empty' => true,
            '--no-interaction' => true,
        ]);
    }
}
