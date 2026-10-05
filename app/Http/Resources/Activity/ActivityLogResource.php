<?php

namespace App\Http\Resources\Activity;

use App\Models\Account;
use App\Models\ActivityLog;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Product;
use App\Models\Variant;
use App\Support\Activity\ActivityActionLabel;
use App\Support\Activity\ActivityKind;
use App\Support\Activity\ActivityNarrative;
use App\Support\Activity\ActivityVisibility;
use App\Support\Members\DeactivatedMemberNames;
use App\Support\Permissions;
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
 * Controller-ul e responsabil de eager-loading
 * (`with(['user:id,name', 'auditable' => ActivityVisibility::eagerLoad(...)])`) — Resource-ul
 * NU declanșează el însuși interogări suplimentare per rând. `auditable` e OBLIGATORIU de când
 * `subjectName` e în formă: proiectul interzice lazy loading, deci absența lui ar arunca, nu ar
 * încetini. `eagerLoad(...)` nu e opțional nici el: fără `order` adus odată cu factura, masca
 * din `ActivityVisibility` cade ÎNCHIS și Agentul își pierde numele facturilor PROPRII.
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
        // Calculat O DATĂ și pasat mai departe — vezi `ActivityNarrative::describe()`.
        $kind = ActivityKind::of($this->resource);

        // Fraza rămâne („Created Invoice"), numele, linkul ȘI valorile dispar — vezi
        // `ActivityVisibility`. Pentru orice tip în afară de `Invoice` iese `true`.
        $mayName = ActivityVisibility::mayNameSubject($this->resource, $request->user());

        return [
            'id' => $this->id,
            'action' => $this->action,
            // ADR-022/FR-I18N-04 — enum ÎNCHIS al coloanei, tradus prin catalog
            // (`lang/{en,fr}/activity.php:actions`), nu prin transformare de șir.
            'actionLabel' => ActivityActionLabel::resolve($this->action),
            // CE s-a întâmplat, derivat (`ActivityKind`) — `updated` acoperă deopotrivă o
            // mutare de etapă, o factură încasată și o editare de titlu. Ecranul alege iconul
            // și tenta din ASTA, ca feed-ul dashboard-ului.
            'kind' => $kind,
            // Fraza compusă și tradusă, și numele PROPRIU al înregistrării atinse — aceeași
            // sursă ca feed-ul (`ActivityNarrative`). Fără ele, pagina asta arăta
            // cincisprezece rânduri „Updated" la rând, fără să spună CARE afacere.
            'description' => ActivityNarrative::describe($this->resource, $kind),
            'subjectName' => $mayName ? ActivityNarrative::subjectName($this->resource) : null,
            // FR-TEN-04 — un membru dezactivat rămâne vizibil ca AUTOR al unei acțiuni
            // trecute („(deactivated)"), la fel ca peste tot unde numele unui membru apare
            // ca referință istorică (§7.4, ADR-011). `null` = acțiune de sistem (§17.1).
            'actor' => $this->user
                ? ['id' => $this->user->id, 'name' => DeactivatedMemberNames::label($this->user->name, $this->user->id)]
                : null,
            // Aceeași decizie ca la `subjectName`, și nu un detaliu: un rând `created` scris
            // pe calea reală (`ActivityLogObserver` → `ChangedAttributes::snapshot()`) poartă
            // instantaneul COMPLET — `invoice_number`, `total`, `balance_due`. Mascarea doar
            // a numelui ar fi fost cosmetică, numărul plecând un nivel mai jos în payload.
            'oldValues' => $mayName ? $this->visibleValues($this->old_values, $request) : null,
            'newValues' => $mayName ? $this->visibleValues($this->new_values, $request) : null,
            'createdAt' => $this->created_at?->toIso8601String(),
            'bulkOperationId' => $this->bulk_operation_id,
            'entityUrl' => $mayName ? $this->entityUrl() : null,
        ];
    }

    /**
     * §7.4 — `variants.cost` (marja) e ascunsă pentru Agent și Viewer „la nivel de
     * `VariantResource`, nu doar în UI". Istoricul unei variante poartă ACEEAȘI coloană în
     * `old_values`/`new_values`, care până acum plecau brute: `ExcludedAttributes` redactează
     * parole și token-uri, nu coloane cu vizibilitate pe rol.
     *
     * Scurgerea e reală, nu teoretică: `VariantPolicy::view()` cere doar `products.view`, pe
     * care Agent și Viewer îl au, iar `HistoryTab` e montat pe `Products/Show`. Un Viewer care
     * citea `/activity/entity/variant/{id}` primea marja în `newValues.cost`. Nu e adusă de
     * acest lot — `oldValues`/`newValues` plecau la fel și înainte — dar e găsită de auditul lui.
     *
     * @param  array<string, mixed>|null  $values
     * @return array<string, mixed>|null
     */
    private function visibleValues(?array $values, Request $request): ?array
    {
        if ($values === null || $this->auditable_type !== Variant::class) {
            return $values;
        }

        $user = $request->user();

        if ($user !== null && Permissions::canViewCost($user)) {
            return $values;
        }

        unset($values['cost']);

        return $values === [] ? null : $values;
    }

    private function entityUrl(): ?string
    {
        // `auditable === null` NU e redundant cu verificările de coloană: un rând de ȘTERGERE
        // păstrează tipul și id-ul, dar înregistrarea nu mai există (fizic, sau ascunsă de
        // `SoftDeletingScope`/`NotAnonymizedContactScope`). Fără verificarea asta, „Deleted
        // account" era un link către un 404 — și de când rândul poartă fraza întreagă, nu mai
        // e un „Deleted" discret, ci o propoziție subliniată la hover.
        if ($this->auditable_type === null || $this->auditable_id === null
            || $this->auditable === null || ! app()->bound('tenant')) {
            return null;
        }

        $segment = self::URL_SEGMENTS[$this->auditable_type] ?? null;

        if ($segment === null) {
            return null;
        }

        return '/'.app('tenant')->slug."/{$segment}/{$this->auditable_id}";
    }
}
