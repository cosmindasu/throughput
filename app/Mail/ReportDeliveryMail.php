<?php

namespace App\Mail;

use App\Mail\Concerns\AttributesSentEmailToTenant;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Livrarea unui raport programat sau manual (specs.md §16.2 pct. 4, ADR-009: Scheduler →
 * job → `Mailable`, transport Resend).
 *
 * Mutată în `app/Mail/` la review (fix P3) — locuia în `App\Support\Reports` doar cât timp
 * `app/Mail` era rezervat lotului care construia mecanismul de interceptare (§22.3); acum
 * e liber.
 *
 * NU implementează `ShouldQueue`: „livrare în coadă" e deja asigurată de
 * `App\Jobs\Reports\DeliverReportJob` (job de TENANT, el însuși pus în coadă) — trimiterea
 * efectivă (`Mail::to(...)->send(...)`) rulează SINCRON în interiorul acelui job, ca să nu
 * dubleze un hop de coadă. Constructorul primește DOAR scalari (§6.3).
 *
 * `AttributesSentEmailToTenant` (fix P3, X-Throughput-Tenant-Id) — captează tenantul
 * CURENT la construcție, cât `DeliverReportJob` încă e în interiorul lui
 * `TenantContext::run()` (citirea rândului `report_runs`), înainte ca trimiterea efectivă
 * să iasă din context (ADR-013). Fără el, rândul din jurnalul „Sent Emails" ar avea
 * `tenant_id = null` — invizibil în Settings-ul tenantului care a programat raportul.
 *
 * „Fișierul atașat SAU un link de descărcare cu expirare" (§16.2 pct. 4) — implementată
 * doar prima variantă. Motiv notat în raportul lotului K: `recipients` sunt adrese arbitrare,
 * fără cont în aplicație (persona „contabil extern", §5) — un link ar cere o rută de
 * descărcare SEMNATĂ, fără autentificare, care nu există încă în convențiile proiectului.
 * Atașamentul acoperă complet US-REP-01/FR-REP-03 pentru volumul de demo.
 */
final class ReportDeliveryMail extends Mailable
{
    use AttributesSentEmailToTenant;

    public function __construct(
        public readonly string $reportName,
        public readonly string $formatLabel,
        public readonly int $rowCount,
        public readonly string $attachmentDisk,
        public readonly string $attachmentPath,
        public readonly string $attachmentName,
        public readonly string $attachmentMime,
        // ADR-022, specs.md §15.8 FR-I18N-05 — limba DESTINATARULUI (aici: creatorul
        // raportului, singura aproximare posibilă când destinatarii sunt adrese arbitrare
        // fără cont), NU implicitul cererii care a declanșat livrarea. `null` păstrează
        // comportamentul de dinaintea acestui lot (fallback pe `App::getLocale()` ambiental,
        // via `Illuminate\Support\Traits\Localizable`), pentru apelanți care încă nu-l
        // furnizează. `App\Jobs\Reports\DeliverReportJob` îl transmite mereu explicit.
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
            subject: trans('mail.report_delivery.subject', ['report' => $this->reportName], $this->locale),
        );
    }

    public function content(): Content
    {
        // A11Y-07 — vezi nota din `App\Mail\SubscriptionCanceledMail::content()`.
        return new Content(view: 'reports.mail.delivery', with: ['subject' => $this->subject]);
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        return [
            Attachment::fromStorageDisk($this->attachmentDisk, $this->attachmentPath)
                ->as($this->attachmentName)
                ->withMime($this->attachmentMime),
        ];
    }
}
