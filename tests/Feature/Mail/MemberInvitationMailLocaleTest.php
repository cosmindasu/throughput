<?php

namespace Tests\Feature\Mail;

use App\Mail\MemberInvitationMail;
use Tests\TestCase;

/**
 * FR-I18N-04, ADR-022, Lotul I18N Val 5 — corpul ÎNTREG al
 * `resources/views/members/mail/invitation.blade.php` trece prin catalog
 * (`lang/{en,fr}/mail.php`, grupul `member_invitation`), completat la a doua trecere a
 * Valului 5. Prima trecere mutase doar butonul de acceptare (`accept_cta`) și lăsase
 * restul literal, în engleză — vezi docblock-ul blade-ului pentru motivul exact.
 *
 * Fișier NOU: `App\Mail\MemberInvitationMail` nu avea NICIO acoperire de test înainte de
 * acest val, la niciun nivel — nici măcar randarea implicită, în engleză.
 *
 * Randează șablonul DIRECT prin `Mailable::render()` (ca `InvoicePdfTranslationTest` —
 * `View::make(...)->render()` — dar aici prin `Mailable`, ca să treacă și prin
 * `Illuminate\Support\Traits\Localizable::withLocale()` pe care `->locale()` îl declanșează
 * la randare), fără coadă și fără `Mail::fake()`: nu verificăm LIVRAREA (acoperită de
 * `DemoInterceptingTransportTest`/`MemberInvitationTest`), doar CONȚINUTUL randat.
 */
class MemberInvitationMailLocaleTest extends TestCase
{
    public function test_the_invitation_body_renders_in_french(): void
    {
        $html = $this->renderInvitation('fr');

        $this->assertStringContainsString('>Accepter l’invitation<', $html);
        $this->assertStringNotContainsString('>Accept the invitation<', $html);

        $this->assertStringContainsString('<p>Bonjour,</p>', $html);
        $this->assertStringContainsString(
            'Vous avez été invité(e) par <strong>Owner Person</strong> à rejoindre'
            .' <strong>Marlin Fasteners &amp; Supply Co.</strong> sur Throughput en tant'
            .' que <strong>Agent</strong>.',
            $html
        );
        $this->assertStringContainsString(
            'Ce lien est valable 7 jours. S’il expire, demandez à Owner Person de vous en'
            .' envoyer un nouveau.',
            $html
        );
        $this->assertStringContainsString(
            'Si vous ne vous attendiez pas à cette invitation, vous pouvez ignorer cet'
            .' e-mail — il ne se passe rien tant que vous n’avez pas accepté.',
            $html
        );

        // Regresie EXPLICITĂ, nu o presupunere: la prima trecere a Valului 5, această
        // aserțiune dovedea că restul corpului RĂMÂNEA englezesc sub locale francez.
        // Inversată acum, fiindcă exact asta repară a doua trecere.
        $this->assertStringNotContainsString('invited you to join', $html);
    }

    public function test_the_invitation_body_stays_english_by_default(): void
    {
        $html = $this->renderInvitation(null);

        $this->assertStringContainsString('>Accept the invitation<', $html);

        $this->assertStringContainsString('<p>Hi,</p>', $html);
        $this->assertStringContainsString(
            '<strong>Owner Person</strong> invited you to join'
            .' <strong>Marlin Fasteners &amp; Supply Co.</strong> on Throughput as'
            .' <strong>Agent</strong>.',
            $html
        );
        $this->assertStringContainsString(
            'This link is valid for 7 days. If it expires, ask Owner Person to send a new'
            .' one.',
            $html
        );
        $this->assertStringContainsString(
            "If you weren't expecting this invitation, you can ignore this email —"
            .' nothing happens until you accept.',
            $html
        );
    }

    private function renderInvitation(?string $locale): string
    {
        return (new MemberInvitationMail(
            workspaceName: 'Marlin Fasteners & Supply Co.',
            workspaceSlug: 'marlin',
            invitedByName: 'Owner Person',
            roleName: 'Agent',
            acceptUrl: 'https://throughput.test/invitations/abc123/accept',
            expiresInDays: 7,
            locale: $locale,
        ))->render();
    }
}
