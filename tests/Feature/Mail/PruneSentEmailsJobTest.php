<?php

namespace Tests\Feature\Mail;

use App\Jobs\System\PruneSentEmailsJob;
use App\Models\Scopes\TenantScope;
use App\Models\SentEmail;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use App\Support\Permissions;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * BR-DEMO-02, specs.md §22.3 — retenția jurnalului „Sent Emails". Acoperă AMBELE trecute
 * ale jobului: rândurile de tenant (§7.2, modelul `PruneExpiredExportsJob`) ȘI rândurile
 * fără tenant (FR-PUB-05), care devin vizibile doar sub trecerea FĂRĂ context (politica RLS
 * din migrația `sent_emails`).
 */
class PruneSentEmailsJobTest extends TestCase
{
    public function test_it_deletes_old_rows_for_a_tenant_and_keeps_recent_ones(): void
    {
        $tenant = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $this->makeMember($tenant, 'demo.owner@throughput.dev', Permissions::OWNER);

        TenantContext::run($tenant, function (): void {
            $this->makeSentEmail('Old enough to prune', now()->subDays(PruneSentEmailsJob::retentionDays() + 1));
            $this->makeSentEmail('Too recent to prune');
        });

        (new PruneSentEmailsJob)->handle();

        TenantContext::run($tenant, function (): void {
            $this->assertSame(1, SentEmail::query()->count());
            $this->assertSame('Too recent to prune', SentEmail::query()->value('subject'));
        });
    }

    public function test_it_deletes_old_tenant_less_rows_without_ever_needing_a_tenant_context(): void
    {
        $this->clearDatabaseTenantContext();

        $this->makeSentEmail('Old password reset', now()->subDays(PruneSentEmailsJob::retentionDays() + 1));
        $recent = $this->makeSentEmail('Recent password reset');

        (new PruneSentEmailsJob)->handle();

        $remaining = SentEmail::withoutGlobalScope(TenantScope::class)->whereNull('tenant_id')->pluck('subject');

        $this->assertSame([$recent->subject], $remaining->all());
    }

    private function makeSentEmail(string $subject, ?Carbon $createdAt = null): SentEmail
    {
        $model = new SentEmail([
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

        // `created_at` explicit, ÎNAINTE de `save()`: modelul e append-only
        // (App\Concerns\AppendOnly), dar asta gardează UPDATE-uri pe un rând EXISTENT — o
        // proprietate „dirty" setată înainte de PRIMUL `save()` nu e supraseris de
        // `updateTimestamps()` (Eloquent nu atinge o coloană deja modificată).
        if ($createdAt !== null) {
            $model->created_at = $createdAt;
        }

        $model->save();

        return $model;
    }
}
