<?php

namespace App\Http\Resources\Activity;

use App\Models\Account;
use App\Models\ActivityLog;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Product;
use App\Support\Activity\ActivityActionLabel;
use App\Support\Activity\ActivityKind;
use App\Support\Activity\ActivityNarrative;
use App\Support\Members\DeactivatedMemberNames;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * FR-AUD-02/03, §17.3 — formă COMUNĂ pentru ambele ecrane ale jurnalului:
 *
 *  - `Activity/Index` (tenant-wide, `ActivityLogController::index()`) — plus `entityUrl`,
 *    ca lista să poată lega fiecare rând de entitatea lui;
 *  - tab-ul „History" montat pe o entitate (`ActivityLogController::forEntity()`,
 *    `resources/js/Components/History/HistoryTab.tsx`) — `entityUrl` iese `null` acolo
 *    (pagina curentă E entitatea, un link către ea însăși n-ar avea rost, deși tehnic
 *    tot ajunge să fie calculat identic — mai simplu decât un al doilea Resource).
 *
 * `App\Models\Variant` NU are pagină de detaliu proprie (istoricul ei se vede din
 * `Products/Show`, per raportul lotului) — `entityUrl` iese `null` pentru ea, deliberat:
 * un link către o rută inexistentă ar fi mai rău decât absența lui.
 *
 * Controller-ul e responsabil de eager-loading (`with(['user:id,name', 'auditable'])`) —
 * Resource-ul NU declanșează el însuși interogări suplimentare per rând. `auditable` e
 * OBLIGATORIU de când `subjectName` e în formă: proiectul interzice lazy loading, deci
 * absența lui ar arunca, nu ar încetini.
 *
 * @mixin ActivityLog
 */
class ActivityLogResource extends JsonResource
{
    /** Alias-urile din `App\Support\Activity\AuditableResources`, pentru link-ul din listă. */
    private const URL_SEGMENTS = [
        Account::class => 'accounts',
        Contact::class => 'contacts',
        Deal::class => 'deals',
        Product::class => 'products',
        Order::class => 'orders',
        Invoice::class => 'invoices',
    ];

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'action' => $this->action,
            // ADR-022/FR-I18N-04 — enum ÎNCHIS al coloanei, tradus prin catalog
            // (`lang/{en,fr}/activity.php:actions`), nu prin transformare de șir.
            'actionLabel' => ActivityActionLabel::resolve($this->action),
            // CE s-a întâmplat, derivat (`ActivityKind`) — `updated` acoperă deopotrivă o
            // mutare de etapă, o factură încasată și o editare de titlu. Ecranul alege iconul
            // și tenta din ASTA, ca feed-ul dashboard-ului.
            'kind' => ActivityKind::of($this->resource),
            // Fraza compusă și tradusă, și numele PROPRIU al înregistrării atinse — aceeași
            // sursă ca feed-ul (`ActivityNarrative`). Fără ele, pagina asta arăta
            // cincisprezece rânduri „Updated" la rând, fără să spună CARE afacere.
            'description' => ActivityNarrative::describe($this->resource),
            'subjectName' => ActivityNarrative::subjectName($this->resource),
            // FR-TEN-04 — un membru dezactivat rămâne vizibil ca AUTOR al unei acțiuni
            // trecute („(deactivated)"), la fel ca peste tot unde numele unui membru apare
            // ca referință istorică (§7.4, ADR-011). `null` = acțiune de sistem (§17.1).
            'actor' => $this->user
                ? ['id' => $this->user->id, 'name' => DeactivatedMemberNames::label($this->user->name, $this->user->id)]
                : null,
            'oldValues' => $this->old_values,
            'newValues' => $this->new_values,
            'createdAt' => $this->created_at?->toIso8601String(),
            'bulkOperationId' => $this->bulk_operation_id,
            'entityUrl' => $this->entityUrl(),
        ];
    }

    private function entityUrl(): ?string
    {
        if ($this->auditable_type === null || $this->auditable_id === null || ! app()->bound('tenant')) {
            return null;
        }

        $segment = self::URL_SEGMENTS[$this->auditable_type] ?? null;

        if ($segment === null) {
            return null;
        }

        return '/'.app('tenant')->slug."/{$segment}/{$this->auditable_id}";
    }
}
