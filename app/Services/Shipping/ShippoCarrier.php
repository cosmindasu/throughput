<?php

namespace App\Services\Shipping;

use App\Models\Account;
use App\Models\Order;
use App\Models\Shipment;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * ADR-010, Faza 5 — a doua implementare reală a `ShippingCarrier`, peste
 * `https://api.goshippo.com` (sandbox — FR-ORD-01/BR-DEMO-03: `credentials['api_key']`
 * vine mereu dintr-un cont `shippo_test_...`, niciodată live, în acest deployment).
 * `CarrierResolver::resolve()` e SINGURUL loc care o instanțiază, cu credențialele
 * decriptate ale rândului `tenant_carrier_settings` activ.
 *
 * Fluxul e cel real al API-ului Shippo, în DOUĂ apeluri secvențiale (nu unul): un API de
 * curierat nu poate cumpăra o etichetă fără să știe mai întâi CE tarife există pentru
 * expedierea respectivă.
 *
 *   1. `POST /shipments/` (`async=false`) — Shippo calculează tarifele („rates") pentru
 *      perechea de adrese + colet. Dacă adresa de destinație e invalidă sau niciun tarif
 *      nu poate fi calculat, Shippo răspunde totuși cu `2xx` — eroarea e ÎN CORPUL
 *      răspunsului (`status`/`messages`), nu în codul HTTP. Contractul din
 *      `ShippingCarrier` cere exact asta pentru un eșec RAPORTAT: `ShippingLabelFailed`.
 *   2. `POST /transactions/` cu `rate` = cel mai ieftin tarif întors la pasul 1 —
 *      cumpărarea propriu-zisă a etichetei. Același tipar: `2xx` cu `status=ERROR` în corp
 *      înseamnă un eșec RAPORTAT (ex. „service unavailable" de la transportator).
 *
 * Orice răspuns non-2xx (401 credențiale greșite/expirate, 429 rate limit, 5xx Shippo jos)
 * NU e un eșec raportat despre EXPEDIEREA asta — e un eșec al INTEGRĂRII (contractul
 * `ShippingCarrier`: „rețea, credențiale, bug intern" rămân tipul lor natural). Un cont
 * sandbox configurat corect nu primește niciodată non-2xx pentru o cerere bine formată,
 * deci distincția e sigură: `RequestException` (via `->throw()`), niciodată
 * `ShippingLabelFailed`.
 *
 * Timeout + retry EXPLICITE (task brief lot D): un sandbox căzut nu trebuie să țină jobul
 * blocat până la `GenerateShippingLabelJob::$timeout` (60s) — `timeout()`/`connectTimeout()`
 * mărginesc FIECARE cerere, iar `retry()` reîncearcă STRICT eșecurile de conexiune
 * (`ConnectionException` — exemplul canonic din docs Laravel), niciodată un răspuns 4xx/5xx
 * primit efectiv de la Shippo (acela e deja un răspuns, nu o conexiune eșuată — a-l
 * reîncerca n-ar schimba nimic și ar consuma doar din bugetul de timp al jobului).
 *
 * Audit de securitate P2 — retry-ul se aplică DOAR pe `/shipments/` (o CITIRE — calculul
 * de tarife nu cumpără nimic), NICIODATĂ pe `/transactions/` (cumpărarea propriu-zisă).
 * Motivul: `ConnectionException` acoperă și un timeout de CITIRE a răspunsului, caz în
 * care Shippo poate fi procesat deja cererea pe partea lui — o reîncercare la nivelul
 * ADAPTORULUI ar retrimite atunci un al doilea `POST /transactions/` FĂRĂ niciun marcaj
 * de idempotență (nesigur — Shippo nu documentează un header `Idempotency-Key` de care
 * să ne putem baza aici). Un eșec de conexiune la cumpărare rămâne deci o încercare
 * UNICĂ, care iese cu tipul ei natural (eroare internă pentru apelant). Siguranța vine
 * de la JOB, nu de la adaptor: `GenerateShippingLabelJob` verifică `label_pending` de
 * DOUĂ ori (înainte de rezoluție și chiar înainte de scrierea finală), deci o reluare de
 * JOB (nu de cerere HTTP) peste un shipment deja `label_purchased` nu cumpără a doua
 * etichetă — vezi `GenerateShippingLabelJobTest::test_retrying_the_job_over_an_already_purchased_shipment_does_nothing`.
 *
 * LIMITARE CUNOSCUTĂ, semnalată în raportul Fazei 5 (lot D), nu ascunsă:
 *   - `locations` (§19.1) nu are coloane de adresă — adresa de expediere e o constantă
 *     fixă (`SHIP_FROM`, mai jos), aceeași pentru orice tenant pe `shippo`. O schemă
 *     viitoare care adaugă adresă pe `Location` ar înlocui această constantă cu
 *     `$shipment->location`.
 *   - `order_lines`/`variants` nu au greutate/dimensiuni — parcela (`PARCEL`) e la fel o
 *     constantă. Amândouă sunt simplificări de demo, nu bug-uri: `DemoShippingCarrier` nu
 *     are nevoie deloc de adresă/colet, dar `ShippoCarrier` chiar apelează un API real care
 *     le cere.
 */
final class ShippoCarrier implements ShippingCarrier
{
    private const BASE_URL = 'https://api.goshippo.com';

    private const REQUEST_TIMEOUT_SECONDS = 8;

    private const CONNECT_TIMEOUT_SECONDS = 5;

    /** Total încercări (1 reîncercare), NUMAI pe `ConnectionException` — vezi docblock-ul clasei. */
    private const RETRY_ATTEMPTS = 2;

    private const RETRY_DELAY_MILLISECONDS = 300;

    private const SHIP_FROM = [
        'name' => 'Throughput Fulfillment',
        'street1' => '215 Clayton St',
        'city' => 'San Francisco',
        'state' => 'CA',
        'zip' => '94117',
        'country' => 'US',
    ];

    private const PARCEL = [
        'length' => '10',
        'width' => '8',
        'height' => '4',
        'distance_unit' => 'in',
        'weight' => '2',
        'mass_unit' => 'lb',
    ];

    /**
     * @param  array<string, mixed>  $credentials  `tenant_carrier_settings.credentials`
     *                                             decriptat — `api_key` e singura cheie
     *                                             folosită azi.
     */
    public function __construct(private readonly array $credentials) {}

    public function createLabel(Shipment $shipment): ShippingLabel
    {
        $addressTo = $this->destinationAddress($shipment);

        // Reîncercabil — o CITIRE (calculul de tarife), vezi docblock-ul clasei.
        /** @var array<string, mixed> $shipmentResponse */
        $shipmentResponse = $this->retryableClient()
            ->post('/shipments/', [
                'address_from' => self::SHIP_FROM,
                'address_to' => $addressTo,
                'parcels' => [self::PARCEL],
                'async' => false,
            ])
            ->throw()
            ->json();

        $rate = $this->cheapestReportedRate($shipmentResponse);

        // NICIODATĂ reîncercabil — cumpărarea propriu-zisă, vezi docblock-ul clasei
        // (audit P2): un timeout de citire aici tot ar fi putut ajunge la Shippo.
        /** @var array<string, mixed> $transactionResponse */
        $transactionResponse = $this->client()
            ->post('/transactions/', [
                'rate' => $rate['object_id'],
                'label_file_type' => 'PDF',
                'async' => false,
            ])
            ->throw()
            ->json();

        $this->guardAgainstReportedTransactionFailure($transactionResponse);

        return new ShippingLabel(
            trackingNumber: (string) $transactionResponse['tracking_number'],
            labelUrl: (string) $transactionResponse['label_url'],
            cost: isset($rate['amount']) ? (float) $rate['amount'] : null,
        );
    }

    /**
     * Shippo anulează o etichetă cumpărată prin `POST /refunds/`, keyed pe object_id-ul
     * TRANZACȚIEI care a cumpărat-o — un identificator pe care `shipments` nu-l persistă
     * azi (schema e în afara lotului D, vezi docblock-ul clasei). Nimic din aplicație nu
     * apelează încă `void()` (doar suita de contract, care cere doar „nu aruncă") — un
     * no-op explicit e mai corect decât a PREFACE o anulare pe care n-o putem identifica.
     */
    public function void(Shipment $shipment): void
    {
        // Intenționat gol — vezi docblock-ul metodei.
    }

    /**
     * Shippo găzduiește o pagină publică de urmărire multi-transportator la acest format
     * (`https://tracking.goshippo.com/{tracking_number}`) — suficient pentru contractul
     * de interfață (§11.5 cere un URL „valid-looking", nu neapărat transportatorul exact
     * ales de rata cumpărată, care oricum nu e persistat pe `shipments`, doar `carrier`).
     */
    public function trackingUrl(Shipment $shipment): string
    {
        return "https://tracking.goshippo.com/{$shipment->tracking_number}";
    }

    /**
     * `shipments` nu are propriile coloane de adresă (§11.1) — destinația e adresa de
     * livrare a contului comenzii. Fetch-ul rulează într-o tranzacție SCURTĂ proprie
     * (ADR-014 pct. 5), în afara oricărei tranzacții deschise de apelant: `createLabel()`
     * se cheamă mereu DUPĂ ce `GenerateShippingLabelJob` și-a închis prima tranzacție
     * (ADR-013), deci contextul de tenant din container nu mai e legat — un query
     * Eloquent lazy aici ar arunca `TenantContextMissingException` fără el.
     */
    private function destinationAddress(Shipment $shipment): array
    {
        $tenantId = (string) $shipment->getAttribute('tenant_id');

        $account = TenantContext::run($tenantId, function () use ($shipment): ?Account {
            return Order::query()->with('account')->find($shipment->order_id)?->account;
        });

        if ($account === null) {
            throw new RuntimeException(
                "Shipment {$shipment->getKey()} has no destination account to build a Shippo shipment from."
            );
        }

        $address = $account->shipping_address ?: $account->billing_address ?: [];

        return [
            'name' => $account->name,
            'street1' => $address['line1'] ?? '',
            'city' => $address['city'] ?? '',
            'state' => $address['state'] ?? '',
            'zip' => $address['postal_code'] ?? '',
            'country' => $address['country'] ?: 'US',
        ];
    }

    /**
     * Contractul `ShippingCarrier` (US-ORD-03): „adresă invalidă, serviciu indisponibil"
     * sunt eșecuri RAPORTATE de furnizor — Shippo le întoarce cu `2xx` și `rates: []` sau
     * `status !== 'SUCCESS'` în corp, nu ca eroare HTTP. `ShippingLabelFailed` cu mesajul
     * EXACT primit (`messages[].text`), niciodată reformulat.
     *
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    private function cheapestReportedRate(array $response): array
    {
        $rates = $response['rates'] ?? [];

        if (($response['status'] ?? null) === 'ERROR' || $rates === []) {
            throw new ShippingLabelFailed(
                $this->messagesToText($response) ?? 'Shippo could not calculate a rate for this shipment (check the destination address).'
            );
        }

        usort($rates, static fn (array $a, array $b): int => (
            (float) ($a['amount'] ?? PHP_FLOAT_MAX) <=> (float) ($b['amount'] ?? PHP_FLOAT_MAX)
        ));

        return $rates[0];
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function guardAgainstReportedTransactionFailure(array $response): void
    {
        $succeeded = ($response['status'] ?? null) === 'SUCCESS'
            && ! empty($response['tracking_number'])
            && ! empty($response['label_url']);

        if (! $succeeded) {
            throw new ShippingLabelFailed(
                $this->messagesToText($response) ?? 'Shippo could not complete this label purchase.'
            );
        }
    }

    /**
     * Shippo pune erorile de business în `messages: [{source, code, text}, ...]` —
     * concatenate, ca un shipment cu mai multe probleme (adresă ȘI colet, de exemplu) să
     * nu ascundă vreuna.
     *
     * @param  array<string, mixed>  $response
     */
    private function messagesToText(array $response): ?string
    {
        $messages = $response['messages'] ?? [];

        if (! is_array($messages) || $messages === []) {
            // Fallback — Shippo întoarce uneori erorile de adresă doar în validarea
            // sub-obiectului `address_to`, cu `messages` de top-level goale.
            $messages = $response['address_to']['validation_results']['messages'] ?? [];
        }

        $texts = array_values(array_filter(array_map(
            static fn ($message): ?string => is_array($message) ? ($message['text'] ?? null) : null,
            is_array($messages) ? $messages : [],
        )));

        return $texts === [] ? null : implode(' ', $texts);
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl(self::BASE_URL)
            ->withToken((string) ($this->credentials['api_key'] ?? ''), 'ShippoToken')
            ->acceptJson()
            ->timeout(self::REQUEST_TIMEOUT_SECONDS)
            ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS);
    }

    /**
     * Folosit STRICT pentru `/shipments/` (citire) — vezi docblock-ul clasei pentru de ce
     * `/transactions/` (cumpărarea) folosește `client()`, fără reîncercare.
     */
    private function retryableClient(): PendingRequest
    {
        return $this->client()->retry(
            self::RETRY_ATTEMPTS,
            self::RETRY_DELAY_MILLISECONDS,
            static fn (Throwable $exception): bool => $exception instanceof ConnectionException,
        );
    }
}
