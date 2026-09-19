<?php

namespace App\Mail\Transport;

use App\Models\SentEmail;
use App\Services\Tenancy\TenantContext;
use App\Support\DemoMode;
use App\Support\Mail\DemoEmailAllowlist;
use App\Support\Mail\SentEmailRedactor;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;
use Throwable;

/**
 * BR-DEMO-02, specs.md §22.3, plan §10 — decorează transportul real configurat
 * (`MAIL_MAILER`: `log` local, `resend` în producție — ADR-009), NU îl înlocuiește.
 * Înregistrat o singură dată, la nivelul `App\Mail\InterceptingMailManager`, deci
 * funcționează indiferent de mailer-ul configurat și fără ca vreun `Mailable`/`Notification`
 * să știe de el — cerința explicită de „reutilizat neschimbat" de invitațiile de membri
 * (Faza 5, §6.4).
 *
 * Decizia se ia PER DESTINATAR (to/cc/bcc), pe domeniu ȘI pe adresă exactă, insensibil la
 * majuscule (`App\Support\Mail\DemoEmailAllowlist`). Dacă niciun destinatar nu e în lista
 * albă, transportul real NU e atins deloc — nici măcar cu o listă goală de destinatari.
 * Dacă ORICE destinatar e permis, mesajul chiar PLEACĂ, dar doar către destinatarii permiși:
 * cei din afara listei se scot din To/Cc/Bcc înainte de trimiterea reală (un mesaj mixt nu
 * trebuie să scurgă conținutul către o adresă neautorizată doar pentru că alta era bună).
 *
 * `DEMO_MODE=false` → transport TRANSPARENT, fără interceptare și FĂRĂ jurnal. Motivul
 * (cerut explicit în mandat, motivat aici și în raport): interceptarea + jurnalul sunt un
 * guardrail de DEMO PUBLIC (specs.md §22, nota de secvențiere din intro), nu o
 * caracteristică generală de produs. Cu `DEMO_MODE=false` acest deployment nu mai e demo-ul
 * public cu scriitori anonimi — un jurnal cu conținutul COMPLET al fiecărui email (inclusiv
 * linkuri cu token, chiar redactate) ar fi el însuși un risc NOU pentru utilizatori reali,
 * fără niciun beneficiu care să-l justifice (principiul de minimizare, specs.md §20.5).
 *
 * Verificat LA FIECARE apel (`DemoMode::enabled()`, `DemoEmailAllowlist::isAllowed()`),
 * NICIODATĂ memoizat în proprietăți ale acestei clase: transportul e construit o singură
 * dată per mailer și trăiește cât worker-ul de coadă (Horizon) — „Memoizarea per cerere"
 * din `.ai/rules/tenancy.md`. Singurele proprietăți reținute (`$inner`, `$mailer`) sunt
 * configurare imuabilă, nu stare de cerere/tenant.
 *
 * Atribuirea de tenant a unui rând din jurnal vine din contextul AMBIENT
 * (`App\Concerns\BelongsToTenant`, la `create()`) — SINGURA sursă de „tenant curent" din
 * toată aplicația. Limitare cunoscută, semnalată explicit în raportul pachetului: un
 * `Mailable`/`Notification` `ShouldQueue` se livrează efectiv din jobul intern al
 * framework-ului (`Illuminate\Mail\SendQueuedMailable` / `SendQueuedNotifications`), care nu
 * poartă niciun middleware de tenant — la acel moment contextul ambiental poate fi deja gol
 * (ADR-013: apelul extern stă explicit ÎN AFARA tranzacției/contextului care l-a pregătit).
 * Header-ul opțional `X-Throughput-Tenant-Id` (citit mai jos, scos din mesajul livrat real
 * înainte de trimitere) e o supapă explicită pentru joburile care vor atribuire corectă în
 * ciuda acestui gol — complet opțională, nimic nu se schimbă dacă nu e folosită. Modul
 * RECOMANDAT de a-l seta e `App\Mail\Concerns\AttributesSentEmailToTenant` (un trait de
 * `Mailable`, care captează tenantul la CONSTRUCȚIE, cât încă există context) — vezi
 * docblock-ul acelui trait pentru motiv și pentru garda „nu poate fi omis tăcut".
 */
final class DemoInterceptingTransport implements TransportInterface
{
    private const TENANT_HEADER = 'X-Throughput-Tenant-Id';

    public function __construct(
        private readonly TransportInterface $inner,
        private readonly string $mailer,
    ) {}

    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        if (! DemoMode::enabled()) {
            return $this->inner->send($message, $envelope);
        }

        if (! $message instanceof Email) {
            // Nu putem introspecta destinatarii unui mesaj MIME brut fără un antet
            // structurat de To/Cc/Bcc. Nu se întâmplă azi (Mailer::sendSymfonyMessage()
            // construiește mereu un Symfony\Component\Mime\Email), păstrat ca gardă
            // explicită — livrare normală, nu o interceptare oarbă pe o presupunere greșită.
            return $this->inner->send($message, $envelope);
        }

        $envelope ??= Envelope::create($message);

        $breakdown = $this->classifyRecipients($message);

        $allowedAddresses = array_values(array_filter(array_map(
            static fn (array $entry): ?Address => $entry['allowed']
                ? new Address($entry['address'], (string) ($entry['name'] ?? ''))
                : null,
            $breakdown,
        )));

        $sentMessage = null;
        $deliveryFailure = null;

        if ($allowedAddresses !== []) {
            $filteredMessage = $this->withOnlyAllowedRecipients($message, $breakdown);
            $filteredEnvelope = new Envelope($envelope->getSender(), $allowedAddresses);

            // Nu se scrie NICIODATĂ un rând „delivered" fals pentru un email care n-a
            // plecat cu adevărat: prindem eșecul transportului real AICI, ca `journal()`
            // (mai jos) să poată înregistra `failed`, distinct de `intercepted`/`partial`.
            try {
                $sentMessage = $this->inner->send($filteredMessage, $filteredEnvelope);
            } catch (Throwable $e) {
                $deliveryFailure = $e;
            }
        }

        // O scriere de jurnal picată (coloană prea scurtă, bază de date jos) NU are voie
        // să rupă trimiterea — recuperarea parolei (FR-PUB-05) trece prin exact acest
        // transport, sincron, în cererea HTTP: o excepție nescăpată aici ar întoarce 500 în
        // loc de mesajul generic („same message regardless"), o încălcare directă a
        // criteriului de acceptanță. `report()` duce eroarea în Sentry (§25.2); trimiterea
        // (sau eșecul ei real, de mai jos) continuă neatinsă.
        try {
            $this->journal($message, $breakdown, $deliveryFailure !== null);
        } catch (Throwable $e) {
            report($e);
        }

        // Eșecul REAL de livrare se re-aruncă, neschimbat, DUPĂ încercarea de jurnalizare:
        // semantica de retry a jobului apelant (`DeliverReportJob::tries`, etc.) rămâne
        // intactă — jurnalul observă eșecul, nu-l absoarbe.
        if ($deliveryFailure !== null) {
            throw $deliveryFailure;
        }

        return $sentMessage;
    }

    public function __toString(): string
    {
        return (string) $this->inner;
    }

    /**
     * Doar pentru teste: transportul real din spatele interceptării (ex: `ArrayTransport`,
     * ca să se poată verifica direct ce a plecat cu adevărat).
     */
    public function innerTransport(): TransportInterface
    {
        return $this->inner;
    }

    /**
     * @return list<array{type: string, address: string, name: ?string, allowed: bool}>
     */
    private function classifyRecipients(Email $message): array
    {
        $breakdown = [];

        foreach (['to' => $message->getTo(), 'cc' => $message->getCc(), 'bcc' => $message->getBcc()] as $type => $addresses) {
            // Deduplicat pe adresă, insensibil la majuscule, ÎN ACELAȘI câmp — un
            // `to: [a@x, a@x]` nu e o breșă, dar unele servere SMTP refuză un `RCPT TO`
            // duplicat, iar altele livrează de două ori. `Envelope::setRecipients()` nu
            // deduplică singur (verificat în sursă), deci facem asta aici, o singură dată,
            // pentru livrarea reală ȘI pentru jurnal deopotrivă.
            $seen = [];

            foreach ($addresses as $address) {
                $key = mb_strtolower($address->getAddress());

                if (isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;

                $breakdown[] = [
                    'type' => $type,
                    'address' => $address->getAddress(),
                    'name' => $address->getName() !== '' ? $address->getName() : null,
                    'allowed' => DemoEmailAllowlist::isAllowed($address->getAddress()),
                ];
            }
        }

        return $breakdown;
    }

    /**
     * @param  list<array{type: string, address: string, name: ?string, allowed: bool}>  $breakdown
     */
    private function withOnlyAllowedRecipients(Email $message, array $breakdown): Email
    {
        $filtered = clone $message;

        foreach (['to', 'cc', 'bcc'] as $type) {
            $allowed = array_values(array_filter(
                $breakdown,
                static fn (array $entry): bool => $entry['type'] === $type && $entry['allowed'],
            ));

            $addresses = array_map(
                static fn (array $entry): Address => new Address($entry['address'], (string) ($entry['name'] ?? '')),
                $allowed,
            );

            match ($type) {
                'to' => $filtered->to(...$addresses),
                'cc' => $filtered->cc(...$addresses),
                'bcc' => $filtered->bcc(...$addresses),
            };
        }

        // Metadată internă, niciodată destinată destinatarului real.
        if ($filtered->getHeaders()->has(self::TENANT_HEADER)) {
            $filtered->getHeaders()->remove(self::TENANT_HEADER);
        }

        return $filtered;
    }

    /**
     * @param  list<array{type: string, address: string, name: ?string, allowed: bool}>  $breakdown
     */
    private function journal(Email $message, array $breakdown, bool $deliveryFailed): void
    {
        $allowedCount = count(array_filter($breakdown, static fn (array $entry): bool => $entry['allowed']));

        // `failed` e distinct de `intercepted`/`partial`: acelea descriu o DECIZIE a
        // acestui transport (adresa nu era în listă), asta descrie un EȘEC al
        // transportului real (Resend jos, timeout) — pentru un mesaj mixt, partea
        // interceptată tot s-a decis, doar partea permisă n-a ajuns.
        $status = match (true) {
            $deliveryFailed => SentEmail::STATUS_FAILED,
            $breakdown === [] || $allowedCount === 0 => SentEmail::STATUS_INTERCEPTED,
            $allowedCount === count($breakdown) => SentEmail::STATUS_DELIVERED,
            default => SentEmail::STATUS_PARTIAL,
        };

        $rawHtml = $message->getHtmlBody();
        $rawText = $message->getTextBody();
        $rawHtml = is_string($rawHtml) ? $rawHtml : null;
        $rawText = is_string($rawText) ? $rawText : null;

        $redactedHtml = SentEmailRedactor::redact($rawHtml);
        $redactedText = SentEmailRedactor::redact($rawText);

        $from = $message->getFrom()[0] ?? null;

        $attributes = [
            'mailer' => $this->mailer,
            'status' => $status,
            'subject' => (string) $message->getSubject(),
            'from_address' => $from?->getAddress(),
            'from_name' => $from !== null && $from->getName() !== '' ? $from->getName() : null,
            'recipients' => array_map(static fn (array $entry): array => [
                'type' => $entry['type'],
                'address' => $entry['address'],
                'name' => $entry['name'],
                'allowed' => $entry['allowed'],
            ], $breakdown),
            'html_body' => $redactedHtml,
            'text_body' => $redactedText,
            'redacted' => SentEmailRedactor::wasRedacted($rawHtml, $redactedHtml)
                || SentEmailRedactor::wasRedacted($rawText, $redactedText),
        ];

        $tenantId = $this->tenantIdFromHeader($message);

        // Într-o SAVEPOINT proprie, nu direct pe conexiune: dacă INSERT-ul eșuează la
        // nivel SQL (ex: `subject` peste `string(500)`), Postgres marchează întreaga
        // tranzacție curentă „aborted" până la un ROLLBACK — fără această izolare, apelantul
        // (`send()`, care prinde deja excepția PHP mai jos) ar moșteni o conexiune otrăvită
        // pentru orice interogare de după, un eșec mai grav decât cel pe care try/catch-ul
        // de acolo încearcă să-l absoarbă. `DB::transaction()`/`TenantContext::run()` fac
        // `ROLLBACK TO SAVEPOINT` automat la excepție, apoi o re-aruncă neschimbată.
        if ($tenantId !== null) {
            // A seta DOAR atributul Eloquent `tenant_id` NU e suficient: politica RLS a
            // tabelei (migrația `sent_emails`) verifică `app.tenant_id` la nivel de
            // CONEXIUNE, nu ce încearcă Eloquent să scrie. Contextul ambiental poate fi deja
            // gol la acest punct (exact motivul pentru care antetul există — vezi
            // App\Mail\Concerns\AttributesSentEmailToTenant), deci trebuie RESTABILIT explicit
            // pentru durata acestui INSERT, altfel „new row violates row-level security
            // policy" — verificat, nu presupus (AttributesSentEmailToTenantTest).
            $attributes['tenant_id'] = $tenantId;

            TenantContext::run($tenantId, function () use ($attributes): void {
                SentEmail::query()->create($attributes);
            });

            return;
        }

        DB::transaction(function () use ($attributes): void {
            SentEmail::query()->create($attributes);
        });
    }

    /**
     * Supapă opțională (vezi docblock-ul clasei): un job care vrea atribuire de tenant
     * corectă în ciuda golului de context descris mai sus poate adăuga acest antet, ÎNAINTE
     * de `send()`, pe mesajul construit — de exemplu, dintr-un `Mailable::withSymfonyMessage()`.
     * Nimic din acest transport nu-l cere.
     */
    private function tenantIdFromHeader(Email $message): ?string
    {
        $header = $message->getHeaders()->get(self::TENANT_HEADER);

        if ($header === null) {
            return null;
        }

        $value = trim((string) $header->getBodyAsString());

        return $value !== '' ? $value : null;
    }
}
