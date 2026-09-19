<?php

namespace Tests\Feature\Settings;

use App\Models\SentEmail;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Support\Permissions;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Izolarea de tenant a jurnalului „Sent Emails" (BR-DEMO-02, specs.md §22.3) — clasă
 * dedicată, consecvent cu `Tests\Feature\Contacts\ContactIsolationTest`: RBAC-ul stă în
 * `SentEmailsTest`, izolarea de tenant stă aici. Citată explicit din migrația
 * `sent_emails` ca dovadă a „zero scurgere cross-tenant" — numele clasei trebuie să rămână
 * exact acesta.
 */
class SentEmailIsolationTest extends TestCase
{
    private Tenant $marlin;

    private Tenant $cascade;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->marlin = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $this->cascade = $this->makeTenant('cascade', 'Cascade Hydraulic Components');

        $this->owner = $this->makeMember($this->marlin, 'demo.owner@throughput.dev', Permissions::OWNER);

        $this->clearDatabaseTenantContext();
    }

    public function test_a_tenant_never_sees_another_tenants_sent_emails(): void
    {
        $this->makeSentEmail($this->marlin, 'Marlin only');
        $this->makeSentEmail($this->cascade, 'Cascade only');

        $this->actingAs($this->owner)
            ->get('/marlin/settings/sent-emails')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->loadDeferredProps(fn (AssertableInertia $page) => $page
                    ->has('sentEmails.data', 1)
                    ->where('sentEmails.data.0.subject', 'Marlin only')
                )
            );
    }

    /**
     * FR-PUB-05 — decizia despre emailurile fără tenant (raportul pachetului): un rând cu
     * `tenant_id = null` (recuperarea parolei) nu trebuie să apară în NICIUN workspace,
     * indiferent cine îl deschide — politica RLS a tabelei (migrația `sent_emails`) îl face
     * invizibil sub orice context de tenant activ.
     */
    public function test_a_tenant_less_row_never_appears_in_any_workspace(): void
    {
        $this->clearDatabaseTenantContext();

        SentEmail::query()->create([
            'mailer' => 'array',
            'status' => SentEmail::STATUS_INTERCEPTED,
            'subject' => 'Reset your password',
            'from_address' => 'noreply@throughput.dbg.ro',
            'from_name' => null,
            'recipients' => [['type' => 'to', 'address' => 'visitor@example.com', 'name' => null, 'allowed' => false]],
            'html_body' => '<p>Reset</p>',
            'text_body' => 'Reset',
            'redacted' => false,
        ]);

        $this->makeSentEmail($this->marlin, 'Marlin only');

        $this->actingAs($this->owner)
            ->get('/marlin/settings/sent-emails')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->loadDeferredProps(fn (AssertableInertia $page) => $page
                    ->has('sentEmails.data', 1)
                    ->where('sentEmails.data.0.subject', 'Marlin only')
                )
            );
    }

    private function makeSentEmail(Tenant $tenant, string $subject): void
    {
        TenantContext::run($tenant, function () use ($subject): void {
            SentEmail::query()->create([
                'mailer' => 'log',
                'status' => SentEmail::STATUS_INTERCEPTED,
                'subject' => $subject,
                'from_address' => 'noreply@throughput.dbg.ro',
                'from_name' => null,
                'recipients' => [['type' => 'to', 'address' => 'someone@example.com', 'name' => null, 'allowed' => false]],
                'html_body' => '<p>Body</p>',
                'text_body' => 'Body',
                'redacted' => false,
            ]);
        });
    }
}
