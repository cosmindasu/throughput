<?php

namespace App\Mail;

use App\Mail\Concerns\AttributesSentEmailToTenant;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * US-TEN-01, §6.4 — „colegul primește un email cu link de acceptare valid 7 zile".
 *
 * ACESTA e fluxul pentru care interceptarea de email s-a construit în Faza 4, cu o fază
 * ÎNAINTE (specs.md §22.3, plan §10): singurul din tot produsul în care o adresă arbitrară,
 * tastată de un vizitator al demo-ului public, devine destinatar. Nimic din clasa asta nu
 * cere interceptarea și nimic n-o poate ocoli — `App\Mail\InterceptingMailManager` decorează
 * transportul o singură dată, sub `Mail::mailer()`, deci orice `Mailable` trece prin el.
 *
 * NU implementează `ShouldQueue`: punerea în coadă e decizia APELANTULUI
 * (`InviteMemberAction` folosește `Mail::to(...)->queue(...)`, ADR-013 — cererea HTTP
 * rulează într-o tranzacție deschisă, deci livrarea nu are voie să fie sincronă). Același
 * tipar ca `ReportDeliveryMail`; constructorul primește DOAR scalari (§6.3).
 *
 * `AttributesSentEmailToTenant` — captează tenantul la CONSTRUCȚIE, cât cererea e încă în
 * contextul workspace-ului. Fără el, rândul din jurnalul „Sent Emails" ar avea
 * `tenant_id = null`: `Illuminate\Mail\SendQueuedMailable` livrează dintr-un job al
 * framework-ului, care nu poartă niciun middleware de tenant.
 */
final class MemberInvitationMail extends Mailable
{
    use AttributesSentEmailToTenant;

    public function __construct(
        public readonly string $workspaceName,
        public readonly string $workspaceSlug,
        public readonly string $invitedByName,
        public readonly string $roleName,
        public readonly string $acceptUrl,
        public readonly int $expiresInDays,
    ) {
        // Ultima linie a constructorului, obligatoriu — vezi docblock-ul trait-ului.
        $this->attributeSentEmailToCurrentTenant();
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: "{$this->invitedByName} invited you to {$this->workspaceName} on Throughput");
    }

    public function content(): Content
    {
        return new Content(view: 'members.mail.invitation');
    }
}
