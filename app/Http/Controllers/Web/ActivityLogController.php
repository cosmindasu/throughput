<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Resources\Activity\ActivityLogResource;
use App\Models\ActivityLog;
use App\Models\Invoice;
use App\Models\Membership;
use App\Support\Activity\ActivityActionLabel;
use App\Support\Activity\ActivityVisibility;
use App\Support\Activity\AuditableResources;
use App\Support\Lists\CursorPage;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Jurnal de activitate — două suprafețe distincte peste ACEEAȘI tabelă (§17):
 *
 *  - `index()` — ecranul TENANT-WIDE (FR-AUD-03), filtrabil pe acțiune/utilizator/interval,
 *    gated de `App\Policies\ActivityLogPolicy::viewAny()` — Owner/Manager văd tot
 *    (`activity_log.view`), Agent doar acțiunile proprii (`activity_log.view_own`, §7.4).
 *  - `forEntity()` — tab-ul „History" (FR-AUD-02), montat pe paginile de detaliu ale
 *    entităților existente. Endpoint JSON simplu (`fetch()`, ca `AccountLookupController`),
 *    NU o pagină Inertia: e o componentă reutilizabilă, incorporată ÎN pagina fiecărei
 *    entități, nu o navigare separată. Gated de Policy-ul ENTITĂȚII înseși (`view`), nu de
 *    `ActivityLogPolicy` — vezi docblock-ul acesteia din urmă pentru motiv.
 */
final class ActivityLogController extends Controller
{
    private const PER_PAGE = 50;

    private const ENTITY_PER_PAGE = 20;

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', ActivityLog::class);

        $user = $request->user();
        $canViewAll = $user->can('activity_log.view');

        $query = ActivityLog::query()
            ->with(['user:id,name', 'auditable' => ActivityVisibility::eagerLoad(...)])
            ->orderByDesc('created_at')
            // Departajare pe ULID (crescător monoton la inserare) — același tipar ca
            // `DashboardController::recentActivity()`/`AccountActivityTimeline`: două
            // rânduri din aceeași secundă nu au altfel o ordine garantată.
            ->orderByDesc('id');

        if (! $canViewAll) {
            $query->where('user_id', $user->getKey());
        }

        if ($request->filled('action')) {
            $query->where('action', $request->string('action')->value());
        }

        // Filtrul de utilizator are sens doar pentru cine vede tot tenantul — pentru
        // Agent, `user_id` e deja fixat mai sus la propria identitate.
        if ($canViewAll && $request->filled('userId')) {
            $query->where('user_id', $request->string('userId')->value());
        }

        if ($request->filled('from')) {
            $query->where('created_at', '>=', CarbonImmutable::parse($request->string('from')->value())->startOfDay());
        }

        if ($request->filled('to')) {
            $query->where('created_at', '<=', CarbonImmutable::parse($request->string('to')->value())->endOfDay());
        }

        // US-BULK-01, §13.3 — linkul „activity_log filtrat pe această operație", de pe
        // `Bulk/Show`. Rămâne aplicabil indiferent de rol: rândurile scrise de o operație
        // în masă poartă deja `user_id` = actorul care a declanșat-o, deci un Agent care
        // filtrează pe PROPRIA lui operație vede exact ce ar vedea și fără acest filtru.
        if ($request->filled('bulkOperationId')) {
            $query->where('bulk_operation_id', $request->string('bulkOperationId')->value());
        }

        $paginator = $query->cursorPaginate(self::PER_PAGE);

        return Inertia::render('Activity/Index', [
            'entries' => CursorPage::make($paginator, ActivityLogResource::class),
            'filters' => [
                'action' => $request->string('action')->value() ?: null,
                'userId' => $request->string('userId')->value() ?: null,
                'from' => $request->string('from')->value() ?: null,
                'to' => $request->string('to')->value() ?: null,
                'bulkOperationId' => $request->string('bulkOperationId')->value() ?: null,
            ],
            'actions' => $this->actionOptions(),
            'members' => $canViewAll ? $this->memberOptions() : [],
            'canFilterByUser' => $canViewAll,
        ]);
    }

    /**
     * FR-AUD-02 — o „pagină" de intrări pentru O SINGURĂ entitate, paginată pe cursor
     * (`?cursor=`), citită de `HistoryTab.tsx` prin `fetch()`. `{type}` restricționat la
     * nivel de rută (`routes/web/activity.php`) la alias-urile din `AuditableResources`.
     */
    public function forEntity(Request $request, string $type, string $id): JsonResponse
    {
        $modelClass = AuditableResources::resolve($type);
        $entity = $modelClass::query()->findOrFail($id);

        Gate::authorize('view', $entity);

        // `ActivityVisibility` decide pe `order.owner_user_id` când entitatea e o factură, iar
        // aici relația nu vine prin `eagerLoad()` (vezi mai jos: entitatea e deja în memorie).
        // `Gate::authorize()` de deasupra garantează deja accesul, dar decizia se ia în
        // Resource, care nu știe asta — deci îi dăm contextul, nu o excepție.
        //
        // Pentru un Agent, `InvoicePolicy::isWithinOwnRecords()` a încărcat deja ACEEAȘI
        // relație pe ACEEAȘI instanță, deci apelul e un no-op; pentru celelalte roluri masca
        // nici nu o citește. Rămâne explicit pe amândouă: altfel singurul lucru care ține
        // numele facturilor pe tab-ul History ar fi un efect secundar al policy-ului.
        if ($entity instanceof Invoice) {
            $entity->loadMissing('order:id,owner_user_id');
        }

        $paginator = ActivityLog::query()
            ->where('auditable_type', $modelClass)
            ->where('auditable_id', $entity->getKey())
            ->with('user:id,name')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->cursorPaginate(self::ENTITY_PER_PAGE);

        // Toate rândurile sunt ALE lui `$entity` — e chiar filtrul de mai sus — iar entitatea
        // e deja încărcată. `with('auditable')` ar fi repetat un `select * from <tabel> where
        // id in (?)` pentru o înregistrare din memorie. Relația se leagă manual, altfel
        // `ActivityNarrative::subjectName()` ar declanșa lazy loading, interzis în proiect.
        $paginator->getCollection()->each(
            fn (ActivityLog $row) => $row->setRelation('auditable', $entity)
        );

        return response()->json(CursorPage::make($paginator, ActivityLogResource::class));
    }

    /**
     * Membrii activi ai tenantului curent — dropdown de filtrare pe utilizator, vizibil
     * doar pentru cine are `activity_log.view` (owner/manager). La fel ca
     * `AccountController::ownerOptions()`: array simplu, nu Resource cu model brut.
     *
     * @return list<array{id: string, name: string}>
     */
    /**
     * Opțiunile filtrului de acțiune: valoarea STABILĂ a enum-ului (merge în query string,
     * deci nu se traduce niciodată) plus eticheta tradusă.
     *
     * Eticheta vine din `ActivityActionLabel`, ACEEAȘI sursă cu `actionLabel` din
     * `ActivityLogResource` — altfel filtrul și tabelul de sub el ar numi diferit aceeași
     * acțiune. Înainte, dropdown-ul randa valoarea brută (`login_failed`), deci nu doar
     * netradusă, ci nici măcar engleză corectă.
     *
     * @return list<array{value: string, label: string}>
     */
    private function actionOptions(): array
    {
        return array_map(
            fn (string $action): array => ['value' => $action, 'label' => ActivityActionLabel::resolve($action)],
            ActivityLog::ACTIONS,
        );
    }

    private function memberOptions(): array
    {
        return Membership::query()
            ->where('status', Membership::STATUS_ACTIVE)
            ->with('user:id,name')
            ->get()
            ->map(fn (Membership $membership) => ['id' => $membership->user->id, 'name' => $membership->user->name])
            ->values()
            ->all();
    }
}
