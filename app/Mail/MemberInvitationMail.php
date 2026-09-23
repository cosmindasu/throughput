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
        // ADR-022, specs.md §15.8 FR-I18N-05 — destinatarul NU are încă un cont
        // (invitație), deci nu are `users.locale`: specificația cere limba sesiunii care a
        // trimis invitația, ca aproximare rezonabilă. I18N-06 — CABLAT la apelantul real
        // (`App\Actions\Members\InviteMemberAction::send()`), care captează
        // `App::getLocale()` cât încă rulează în contextul cererii HTTP (înainte de
        // `Mail::to($email)->queue(...)`) și îl transmite aici explicit. Parametrul e
        // OBLIGATORIU (fără `= null`) — un apelant nou care omite `locale:` trebuie să pice
        // la analiza statică, nu să scurgă tăcut limba ambientală a worker-ului (exact
        // bug-ul pe care `.ai/rules/tenancy.md` îl documentează pentru orice altă valoare
        // memoizată pe un worker de viață lungă). Rămâne nullable ca TIP (nu ca implicit):
        // `Mailable::locale(null)` cade pe fallback-ul ambiental prin
        // `Illuminate\Support\Traits\Localizable::withLocale()`, comportament pe care
        // `App::getLocale()` — mereu un string — nu-l va declanșa niciodată în practică, dar
        // pe care testele îl exersează explicit (`renderInvitation(null)`).
        ?string $locale,
    ) {
        // Ultima linie a constructorului, obligatoriu — vezi docblock-ul trait-ului.
        $this->attributeSentEmailToCurrentTenant();
        $this->locale($locale);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: trans('mail.member_invitation.subject', [
                'inviter' => $this->invitedByName,
                'workspace' => $this->workspaceName,
            ], $this->locale),
        );
    }

    public function content(): Content
    {
        // A11Y-07 — vezi nota din `App\Mail\SubscriptionCanceledMail::content()`.
        return new Content(view: 'members.mail.invitation', with: ['subject' => $this->subject]);
    }
}
