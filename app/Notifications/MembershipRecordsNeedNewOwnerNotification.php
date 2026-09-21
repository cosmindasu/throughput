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
 *
 * ADR-022, specs.md §15.8 FR-I18N-05 — NICIUN `->locale()` explicit aici, spre deosebire
 * de `App\Mail\*` trimise prin `Mail::to()`: `Illuminate\Notifications\ChannelManager`
 * trece prin `Illuminate\Notifications\NotificationSender::sendNow()`, care rezolvă limba
 * INDIVIDUAL, per `$notifiable`, din `User::preferredLocale()` (contractul
 * `HasLocalePreference`, deja implementat de `App\Models\User`) — inclusiv când
 * `Notification::send($colecțieDeOwneri, ...)` trimite către MAI MULȚI destinatari
 * simultan (`App\Http\Controllers\Web\Settings\MembersController`), fiecare primind
 * randarea în PROPRIA limbă. Randarea rulează prin
 * `Illuminate\Support\Traits\Localizable::withLocale()`, care restaurează locale-ul
 * anterior după fiecare destinatar — mecanismul nativ e deja leak-safe pe un worker de
 * coadă cu viață lungă, spre deosebire de joburile proprii din `app/Jobs/`, care trebuie
 * să facă asta manual (`.ai/rules/tenancy.md:123-138`).
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

        // Compuse din DOUĂ `trans_choice()` separate (una per numărător), nu una singură
        // cu doi `:count` — `trans_choice()` alege forma de plural pe baza unui SINGUR
        // număr; combinarea se face aici, în textul simplu `mail.*.unassigned`.
        $deals = trans_choice('mail.membership_records_need_new_owner.deals', $this->openDeals, ['count' => $this->openDeals]);
        $orders = trans_choice('mail.membership_records_need_new_owner.orders', $this->activeOrders, ['count' => $this->activeOrders]);

        return (new MailMessage)
            ->subject(trans_choice('mail.membership_records_need_new_owner.subject', $total, ['count' => $total]))
            ->greeting(__('mail.membership_records_need_new_owner.greeting', ['name' => $notifiable->name]))
            ->line(__('mail.membership_records_need_new_owner.deactivated', [
                'member' => $this->deactivatedMemberName,
                'tenant' => $this->tenantName,
            ]))
            ->line(__('mail.membership_records_need_new_owner.unassigned', ['deals' => $deals, 'orders' => $orders]))
            ->action(__('mail.membership_records_need_new_owner.action'), url("/{$this->workspaceSlug}/unassigned"))
            // Numele rolurilor vin din `lang/{locale}/roles.php`, sursa unică adăugată la
            // Valul 3 — NU scrise literal aici și nici în catalogul de mail. `__()` le
            // rezolvă în limba deja fixată pentru destinatar (`HasLocalePreference`),
            // aceeași în care se randează și restul mesajului.
            ->line(__('mail.membership_records_need_new_owner.footer', [
                'owner' => __('roles.owner'),
                'manager' => __('roles.manager'),
            ]));
    }
}
