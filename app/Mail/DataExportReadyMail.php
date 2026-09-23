<?php

namespace App\Mail;

use App\Mail\Concerns\AttributesSentEmailToTenant;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * FR-GDPR-01 (specs.md §20.5) — „notificare (email + UI) la finalizare, cu link de
 * descărcare `expires_at` (+7 zile)".
 *
 * **Link, nu atașament** — invers față de `ReportDeliveryMail`, deliberat: acolo
 * destinatarii sunt adrese arbitrare, fără cont (persona „contabil extern"), deci un link
 * ar fi cerut o rută semnată, publică. Aici destinatarul e chiar Owner-ul autentificat care
 * a cerut exportul, deci ruta normală de descărcare, gardată de Policy, e exact ce trebuie
 * — iar arhiva unui tenant cu ~30.000 de comenzi (specs.md §21.1) n-ar trece oricum de
 * limitele de dimensiune ale niciunui furnizor de email.
 *
 * NU implementează `ShouldQueue`: livrarea în coadă e deja asigurată de
 * `App\Jobs\Gdpr\FinalizeDataExportJob`, care trimite sincron ÎN AFARA oricărei tranzacții
 * (ADR-013). Un al doilea hop de coadă n-ar adăuga nimic.
 *
 * `AttributesSentEmailToTenant` — captează tenantul la CONSTRUCȚIE, cât jobul e încă în
 * `TenantContext::run()`: fără el, rândul din jurnalul „Sent Emails" (BR-DEMO-02, §22.3) ar
 * avea `tenant_id = null` și ar fi invizibil în Settings-ul workspace-ului care a cerut
 * exportul.
 */
final class DataExportReadyMail extends Mailable
{
    use AttributesSentEmailToTenant;

    public function __construct(
        public readonly string $workspaceName,
        public readonly string $requestedByName,
        public readonly string $downloadUrl,
        public readonly string $expiresOn,
        public readonly int $retentionDays,
        // ADR-022, specs.md §15.8 FR-I18N-05 — limba destinatarului REAL (Owner-ul
        // autentificat care a cerut exportul, `users.locale`), transmisă explicit de
        // `App\Jobs\Gdpr\FinalizeDataExportJob`. `null` = comportamentul dinainte de acest
        // lot (fallback pe locale-ul ambiental).
        ?string $locale = null,
    ) {
        // Ultima linie, obligatoriu (docblock-ul trait-ului) — după ce toate proprietățile
        // scalare sunt atribuite.
        $this->attributeSentEmailToCurrentTenant();
        $this->locale($locale);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: trans('mail.data_export_ready.subject', ['workspace' => $this->workspaceName], $this->locale),
        );
    }

    public function content(): Content
    {
        // A11Y-07 — vezi nota din `App\Mail\SubscriptionCanceledMail::content()`.
        return new Content(view: 'gdpr.mail.export-ready', with: ['subject' => $this->subject]);
    }
}
