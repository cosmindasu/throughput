<?php

namespace App\Mail\Concerns;

use App\Models\Scopes\TenantScope;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Headers;
use LogicException;

/**
 * BR-DEMO-02, specs.md §22.3 — atribuirea de tenant a unui rând din jurnalul „Sent Emails"
 * NU trebuie să depindă de disciplina fiecărui autor de `Mailable` (găsit empiric, nu
 * ipotetic: primul consumator real, `App\Jobs\Reports\DeliverReportJob` +
 * `App\Support\Reports\ReportDeliveryMail`, scrie sistematic `tenant_id = null`, pentru că
 * trimite efectiv DUPĂ ce `TenantContext::run()` a închis contextul — corect per ADR-013,
 * dar cu acest cost. Faza 5 („reutilizat neschimbat" pentru invitațiile de membri, plan
 * §10) ar repeta exact același gol a treia oară dacă mecanismul rămâne pur opțional).
 *
 * `App\Mail\Transport\DemoInterceptingTransport` citește un antet opțional
 * `X-Throughput-Tenant-Id` de pe mesaj; dacă lipsește, se bazează pe contextul ambiental de
 * la momentul trimiterii (de obicei gol, pentru mail livrat dintr-un job — ADR-013). Acest
 * trait e MODUL RECOMANDAT de a seta antetul: captează tenantul la CONSTRUCȚIA
 * `Mailable`-ului, când contextul încă e activ — un `Mailable` se construiește de regulă
 * înăuntrul lui `TenantContext::run()` (citirea datelor), înainte ca jobul apelant să
 * elibereze contextul pentru trimiterea efectivă, care stă STRICT în afara oricărei
 * tranzacții (ADR-013).
 *
 * Un singur pas manual, dar IMPOSIBIL de omis tăcut: `attributeSentEmailToCurrentTenant()`,
 * ultima linie a constructorului. Dacă un `Mailable` folosește trait-ul dar uită s-o cheme,
 * `headers()` raportează explicit (Sentry, §25.2) de fiecare dată când mesajul respectiv se
 * trimite — vizibil în monitorizare, nu descoperit din întâmplare luni mai târziu. Nu
 * blochează trimiterea: o eroare de atribuire nu are voie să devină o eroare de livrare.
 *
 * Folosire:
 *
 *     final class ReportDeliveryMail extends Mailable
 *     {
 *         use AttributesSentEmailToTenant;
 *
 *         public function __construct(public readonly string $reportName, …)
 *         {
 *             $this->attributeSentEmailToCurrentTenant();
 *         }
 *     }
 *
 * @mixin Mailable
 */
trait AttributesSentEmailToTenant
{
    private bool $sentEmailTenantCaptured = false;

    private ?string $sentEmailTenantId = null;

    /**
     * Apelată explicit, ca ULTIMĂ linie a constructorului — după ce toate proprietățile
     * scalare au fost atribuite, exact cum ADR-013 cere ca un `Mailable` cu I/O extern să
     * termine de citit din tenant ÎNAINTE de a ieși din `TenantContext::run()`.
     */
    protected function attributeSentEmailToCurrentTenant(): static
    {
        $this->sentEmailTenantCaptured = true;
        $this->sentEmailTenantId = TenantScope::currentTenantId();

        return $this;
    }

    public function headers(): Headers
    {
        if (! $this->sentEmailTenantCaptured) {
            report(new LogicException(
                static::class.' folosește App\Mail\Concerns\AttributesSentEmailToTenant, dar '
                .'nu a chemat attributeSentEmailToCurrentTenant() în constructor — emailul va '
                .'ajunge în jurnalul „Sent Emails" fără tenant '
                .'(App\Mail\Transport\DemoInterceptingTransport).',
            ));

            return new Headers;
        }

        return new Headers(text: $this->sentEmailTenantId !== null
            ? ['X-Throughput-Tenant-Id' => $this->sentEmailTenantId]
            : []);
    }
}
