<?php

namespace Tests\Feature;

use App\Jobs\System\PruneExpiredInvitationsJob;
use App\Jobs\System\RedactWebhookEventPayloadsJob;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Tests\TestCase;

/**
 * GDPR-03/07/09 (audit 2026-09-23, `docs/reviews/2026-09-23_audit/08-gdpr.md`) — cele trei
 * intrări noi din `routes/console.php`. Aceeași tehnică de verificare a `Schedule` ca
 * `Tests\Feature\DemoResetScheduleTest`/`PruneExpiredExportsJobTest`: rutele de consolă se
 * încarcă abia la bootstrap-ul kernelului de consolă, pe care un test HTTP nu-l pornește
 * singur.
 */
class GdprRetentionScheduleTest extends TestCase
{
    public function test_webhook_payload_redaction_is_scheduled_daily(): void
    {
        $event = $this->eventDescribedAs(RedactWebhookEventPayloadsJob::class);

        $this->assertNotNull($event, 'Intrarea de scheduler pentru redactarea payload-ului webhook-urilor lipsește din routes/console.php.');
        $this->assertSame('0 0 * * *', $event->expression, 'daily() trebuie să rămână la miezul nopții implicit.');
    }

    public function test_expired_invitation_pruning_is_scheduled_daily(): void
    {
        $event = $this->eventDescribedAs(PruneExpiredInvitationsJob::class);

        $this->assertNotNull($event, 'Intrarea de scheduler pentru purjarea invitațiilor expirate lipsește din routes/console.php.');
        $this->assertSame('0 0 * * *', $event->expression, 'daily() trebuie să rămână la miezul nopții implicit — pragul e în ZILE, nu în luni.');
    }

    public function test_failed_jobs_pruning_is_scheduled_daily_with_a_seven_day_retention(): void
    {
        $this->app->make(Kernel::class)->bootstrap();

        $event = collect($this->app->make(Schedule::class)->events())
            ->first(fn (Event $event) => str_contains($event->command ?? '', 'queue:prune-failed'));

        $this->assertNotNull($event, 'Intrarea de scheduler pentru queue:prune-failed lipsește din routes/console.php.');
        $this->assertSame('0 0 * * *', $event->expression, 'daily() trebuie să rămână la miezul nopții implicit.');
        $this->assertStringContainsString('--hours=168', $event->command, 'GDPR-07 cere explicit 168h (7 zile), ca sent_email_retention_days/export_retention_days.');
    }

    private function eventDescribedAs(string $jobClass): ?Event
    {
        $this->app->make(Kernel::class)->bootstrap();

        return collect($this->app->make(Schedule::class)->events())
            ->first(fn (Event $event) => $event->description === $jobClass);
    }
}
