<?php

namespace App\Jobs\Shipping;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Shipment;
use App\Services\Shipping\CarrierResolver;
use App\Services\Shipping\ShippingCarrier;
use App\Services\Shipping\ShippingLabelFailed;
use App\Services\Tenancy\TenantContext;
use App\Support\JobErrorMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * US-ORD-03, ADR-013 — cumpără eticheta ÎN AFARA cererii HTTP. Job de TENANT (ADR-014,
 * pct. 4: `tenantId` scalar, niciodată un `Shipment` serializat — capcana din §6.3), dar
 * structurat ca `App\Jobs\Exports\ExportListJob`/`App\Jobs\Bulk\PlanBulkOperationJob`
 * (P1-003 acolo, ADR-014 pct. 5 aici): tranzacții SCURTE prin `TenantContext::run()`,
 * NICIODATĂ `App\Jobs\Middleware\ApplyTenantContextToJob` — acel middleware ar înfășura tot
 * `handle()` într-o SINGURĂ tranzacție, deci apelul către `ShippingCarrier::createLabel()`
 * (extern, poate dura secunde) ar ține o tranzacție Postgres deschisă exact cazul pe care
 * ADR-013 îl elimină din cererea HTTP — reintrodus pe altă cale dacă jobul l-ar înfășura la fel.
 *
 * Trei faze, nu două: `CarrierResolver::resolve()` (citește `tenant_carrier_settings`) are
 * NEVOIE de context de tenant, deci se cheamă ÎNĂUNTRUL primei tranzacții scurte, nu lângă
 * apelul extern. Rezoluția poate eșua SINGURĂ (furnizor configurat fără adaptor încă —
 * `shippo`, Faza 5): eșecul ăsta nu implică niciun apel extern, deci se scrie direct în
 * ACEEAȘI tranzacție scurtă, fără o a doua fază. Doar apelul REAL către `createLabel()`
 * (extern) stă între tranzacția care-l pregătește și cea care-i scrie rezultatul.
 *
 * Idempotent: verifică `status === label_pending` ÎNAINTE de rezoluție/apelul de curierat
 * (o reluare peste un shipment deja `label_purchased`/`label_failed` nu face nimic) și DIN
 * NOU chiar înainte de scrierea finală (o reluare concurentă câștigată de altă execuție a
 * aceluiași job nu cumpără o a doua etichetă).
 *
 * Code review P1, apărare în adâncime — `CancelOrderAction` reverifică acum
 * `shipments()->exists()` SUB blocare, deci o comandă cu shipment nu mai poate ajunge
 * `cancelled` pe calea normală. Jobul verifică totuși, în AMBELE faze (înainte de apelul
 * de curierat și chiar înainte de scrierea finală), că `Order::status` e încă
 * `confirmed`/`partially_fulfilled` — dacă nu, shipment-ul trece `label_failed` cu un
 * mesaj clar, fără (sau fără să folosească) apelul extern.
 */
final class GenerateShippingLabelJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    /**
     * Code review P2 — `ShippingCarrier::createLabel()` poate arunca orice altă
     * `Throwable` decât `ShippingLabelFailed` (rețea, credențiale, un bug intern al
     * adaptorului): mesajul EI brut nu e sigur de arătat unui Viewer, deci shipment-ul
     * primește acest mesaj generic, iar detaliile reale ajung doar în log (`report()`).
     *
     * I18N-03 — cheie de catalog (`JobErrorMessage`), nu text: tradusă abia la randare
     * (`ShipmentResource`), în locale-ul cererii care randează comanda, nu al workerului
     * care a scris eșecul.
     */
    private const GENERIC_FAILURE_MESSAGE_KEY = 'job_errors.shipment.generic_failure';

    /**
     * Cadrul tradus pentru un eșec RAPORTAT de furnizor; `:reason` e motivul exact al
     * transportatorului, necatalogabil, trecut cuvânt cu cuvânt ca parametru.
     */
    private const CARRIER_REJECTED_MESSAGE_KEY = 'job_errors.shipment.carrier_rejected';

    public function __construct(
        public string $tenantId,
        public string $shipmentId,
    ) {}

    public function handle(CarrierResolver $resolver): void
    {
        /** @var array{shipment: ?Shipment, carrier: ?ShippingCarrier} $prepared */
        $prepared = TenantContext::run($this->tenantId, function () use ($resolver): array {
            $shipment = Shipment::query()->with('order')->find($this->shipmentId);

            if ($shipment === null || $shipment->status !== Shipment::STATUS_LABEL_PENDING) {
                return ['shipment' => null, 'carrier' => null];
            }

            if (! $this->orderIsOpenForShipping($shipment->order)) {
                $shipment->update([
                    'status' => Shipment::STATUS_LABEL_FAILED,
                    'error_message' => $this->orderNoLongerOpenMessage($shipment->order),
                ]);

                return ['shipment' => null, 'carrier' => null];
            }

            try {
                return ['shipment' => $shipment, 'carrier' => $resolver->resolve()];
            } catch (Throwable $e) {
                // Eșec de REZOLUȚIE (ex: `shippo` configurat, fără adaptor încă) — nu
                // implică niciun apel extern, deci se scrie direct aici, fără o fază
                // separată. O rezoluție eșuată e mereu o problemă de CONFIGURARE
                // (`CarrierResolver`), niciodată un `ShippingLabelFailed` raportat de
                // furnizor — mesajul generic, nu cel brut.
                $shipment->update([
                    'status' => Shipment::STATUS_LABEL_FAILED,
                    'error_message' => JobErrorMessage::encode(self::GENERIC_FAILURE_MESSAGE_KEY),
                ]);

                report($e);

                return ['shipment' => null, 'carrier' => null];
            }
        });

        $shipment = $prepared['shipment'];
        $carrier = $prepared['carrier'];

        if ($shipment === null || $carrier === null) {
            return;
        }

        try {
            // Apelul extern — AFARA oricărei tranzacții (ADR-013). `DemoShippingCarrier`
            // nu face niciun apel real (ADR-010), dar interfața nu poate presupune asta
            // pentru viitoarele implementări reale (Shippo, Faza 5).
            $label = $carrier->createLabel($shipment);
        } catch (Throwable $e) {
            $this->markFailed($e);

            return;
        }

        TenantContext::run($this->tenantId, function () use ($label): void {
            $fresh = Shipment::query()->with('order')->find($this->shipmentId);

            if ($fresh === null || $fresh->status !== Shipment::STATUS_LABEL_PENDING) {
                return;
            }

            // Code review P1 — reverificat CHIAR ÎNAINTE de scrierea finală: dacă
            // între apelul extern (de mai sus) și acest moment comanda a ieșit din
            // `confirmed`/`partially_fulfilled` (teoretic imposibil pe căile normale
            // după fix-ul din `CancelOrderAction`, dar verificat oricum — apărare în
            // adâncime), eticheta NU se mai activează pe un shipment orfan.
            if (! $this->orderIsOpenForShipping($fresh->order)) {
                $fresh->update([
                    'status' => Shipment::STATUS_LABEL_FAILED,
                    'error_message' => $this->orderNoLongerOpenMessage($fresh->order),
                ]);

                return;
            }

            $fresh->update([
                'status' => Shipment::STATUS_LABEL_PURCHASED,
                'tracking_number' => $label->trackingNumber,
                'label_url' => $label->labelUrl,
                'cost' => $label->cost,
                'error_message' => null,
            ]);
        });
    }

    private function markFailed(Throwable $e): void
    {
        $isCarrierReported = $e instanceof ShippingLabelFailed;

        TenantContext::run($this->tenantId, function () use ($e, $isCarrierReported): void {
            $fresh = Shipment::query()->find($this->shipmentId);

            if ($fresh !== null && $fresh->status === Shipment::STATUS_LABEL_PENDING) {
                $fresh->update([
                    'status' => Shipment::STATUS_LABEL_FAILED,
                    // Contractul din `ShippingCarrier` (code review P2, US-ORD-03):
                    // `ShippingLabelFailed` e SINGURA excepție al cărei mesaj e sigur de
                    // arătat (motivul specific al furnizorului) — orice altceva e o
                    // eroare internă, mesaj generic, detaliile doar în log.
                    //
                    // I18N-03 — motivul raportat de furnizor (`ShippingLabelFailed`) e text
                    // extern, dinamic, cu contractul „niciodată reformulat": nu se poate
                    // cataloga. Intră deci ca PARAMETRU, cuvânt cu cuvânt, într-un cadru
                    // tradus (`job_errors.shipment.carrier_rejected`) — utilizatorul citește
                    // „transportatorul a refuzat eticheta" în limba lui, iar detaliul exact
                    // al furnizorului rămâne neatins. Ramura GENERICĂ (eroare internă) nu
                    // expune nimic din excepție; detaliile ajung doar în log.
                    'error_message' => $isCarrierReported
                        ? JobErrorMessage::encode(self::CARRIER_REJECTED_MESSAGE_KEY, ['reason' => $e->getMessage()])
                        : JobErrorMessage::encode(self::GENERIC_FAILURE_MESSAGE_KEY),
                ]);
            }
        });

        // Un eșec RAPORTAT de furnizor (adresă invalidă etc.) e un rezultat de business
        // așteptat, nu un bug — `report()` rămâne doar pentru excepția neașteptată, ca
        // Sentry să nu se umple cu „erori" care sunt de fapt răspunsuri normale ale
        // adaptorului.
        if (! $isCarrierReported) {
            report($e);
        }
    }

    private function orderIsOpenForShipping(Order $order): bool
    {
        return in_array($order->status, [OrderStatus::Confirmed, OrderStatus::PartiallyFulfilled], true);
    }

    /**
     * I18N-03 — `:status` e un parametru AMÂNAT (`JobErrorMessage::translatedParam()`), nu
     * `$order->status->label()` apelat aici: `label()` ar traduce ÎN LOCALE-UL JOBULUI (dacă
     * are unul), înghețând limba în coloană — vezi docblock-ul `JobErrorMessage`. Se
     * stochează valoarea BRUTĂ a enum-ului, cu cheia de catalog `enums.order_status.*`
     * atașată; traducerea reală se întâmplă abia în `ShipmentResource`, în locale-ul
     * cererii care randează comanda.
     */
    private function orderNoLongerOpenMessage(Order $order): string
    {
        return JobErrorMessage::encode('job_errors.shipment.order_no_longer_open', [
            'status' => JobErrorMessage::translatedParam('enums.order_status.'.$order->status->value),
        ]);
    }
}
