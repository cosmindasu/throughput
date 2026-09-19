<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * BR-TEN-06 — „Deactivate anyway": toți Owner-ii ACTIVI ai tenantului primesc
 * „N records need a new owner" (Gherkin US-TEN-03). Canal `mail`, pe coadă, ca resetarea
 * de parolă (§22.3) — nu `database`, specs.md nu cere un centru de notificări în MVP.
 *
 * Doar scalare în constructor, nimic tenant-scoped de reinterogat: mesajul se compune
 * integral din ce se știa deja la momentul dezactivării (ADR-013 — un job fără nevoie de
 * context de tenant nu-l cere).
 */
final class MembershipRecordsNeedNewOwnerNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $deactivatedMemberName,
        private readonly string $tenantName,
        private readonly string $workspaceSlug,
        private readonly int $openDeals,
        private readonly int $activeOrders,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $total = $this->openDeals + $this->activeOrders;

        return (new MailMessage)
            ->subject("{$total} records need a new owner")
            ->greeting("Hi {$notifiable->name},")
            ->line("{$this->deactivatedMemberName} was deactivated in {$this->tenantName}, and chose not to reassign their open records right away.")
            ->line("{$this->openDeals} open deal(s) and {$this->activeOrders} active order(s) are now unassigned.")
            ->action('Review in Unassigned', url("/{$this->workspaceSlug}/unassigned"))
            ->line('Nothing was lost — these records are visible to every Owner and Manager until someone reassigns them.');
    }
}
