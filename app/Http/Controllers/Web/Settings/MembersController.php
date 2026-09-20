<?php

namespace App\Http\Controllers\Web\Settings;

use App\Actions\Bulk\DispatchBulkOperationAction;
use App\Actions\Members\UpdateMemberRoleAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Members\DeactivateMembershipRequest;
use App\Http\Requests\Members\UpdateMemberRoleRequest;
use App\Http\Resources\Members\MembershipResource;
use App\Models\Account;
use App\Models\ActivityLog;
use App\Models\Deal;
use App\Models\Membership;
use App\Models\Order;
use App\Models\User;
use App\Notifications\MembershipRecordsNeedNewOwnerNotification;
use App\Support\Bulk\BulkChunkActions;
use App\Support\Bulk\BulkWritableResources;
use App\Support\DemoMode;
use App\Support\Lists\AccountList;
use App\Support\Lists\OrderList;
use App\Support\Members\ActiveOwners;
use App\Support\Members\DeactivatedMemberIds;
use App\Support\Members\OpenRecordCounts;
use App\Support\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings → Members (§6.4, §6.4.1, US-TEN-02/03) — listă, dezactivare (BR-TEN-03/04/06),
 * cu reatribuire opțională prin mecanismul generic de operații în masă (§13.2, BR-BULK-04).
 *
 * `EnsureDemoModeGuardrails` (global, `bootstrap/app.php`) intercepteaza `deactivate()`
 * ÎNAINTE să ajungă aici cât timp `DEMO_MODE=true` (`DemoMode::GUARDED_ACTIONS`,
 * `settings.members.deactivate`) — conturile demo sunt LOGIN-URI PARTAJATE, vezi raportul
 * pachetului.
 */
final class MembersController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Membership::class);

        $memberships = Membership::query()
            ->with(['user:id,name,email', 'user.roles', 'deactivatedBy:id,name'])
            // Ordine EXPLICITĂ, nu alfabetică pe `status`: `orderByDesc('status')` dădea
            // `pending` > `deactivated` > `active` — adică, din clipa în care invitațiile
            // au apărut (US-TEN-01), membrii activi cădeau sub cei dezactivați. Cazul
            // util e invers: cine are acces acum, apoi cine a fost invitat și n-a răspuns,
            // apoi arhiva.
            ->orderByRaw('case status when ? then 0 when ? then 1 else 2 end', [
                Membership::STATUS_ACTIVE,
                Membership::STATUS_PENDING,
            ])
            // `created_at` are precizie 0 în tot proiectul (`.ai/rules/tenancy.md`): doi
            // membri creați în aceeași cerere au EXACT aceeași valoare, deci ordinea lor
            // era la latitudinea planului de execuție. ULID-ul e sortabil cronologic la
            // milisecundă și rupe egalitatea determinist.
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $activeUserIds = $memberships
            ->where('status', Membership::STATUS_ACTIVE)
            ->pluck('user_id')
            ->values()
            ->all();

        $openRecordsByUser = OpenRecordCounts::forUsers($activeUserIds);
        $activeOwnerCount = ActiveOwners::forCurrentTenant()->count();

        $currentUser = $request->user();
        $currentUserIsOwner = $currentUser->hasRole(Permissions::OWNER);

        $canInvite = $currentUser->can('members.invite');

        // §22.2 — al doilea strat, simetric cu `members.deactivate` de mai jos: lotul I a
        // guardat `settings.members.role.update` în DEMO_MODE, fiindcă al doilea drum către
        // „workspace fără Owner" e RETROGRADAREA, nu doar eliminarea (BR-TEN-01 le numește
        // împreună), iar conturile demo sunt LOGIN-URI PARTAJATE — un
        // `demo.manager@throughput.dev` retrogradat la Viewer strică experiența fiecărui
        // vizitator până la resetul de la 03:00 UTC. Butonul trebuie să LIPSEASCĂ, nu să
        // ducă la un refuz (FR-RBAC-01, §7.3).
        //
        // Garda de MEDIU nu ține loc de REGULA de business: BR-TEN-01/02 rămân aplicate
        // necondiționat, în `MembershipPolicy::updateRole()` + `UpdateMemberRoleAction`
        // (sub blocare), cu sau fără DEMO_MODE. `DemoMode::allows()` pe o cheie
        // neînregistrată încă întoarce `true`, deci linia e inertă până la integrare.
        $canUpdateRoleAtAll = $currentUser->can('members.update_role')
            && DemoMode::allows('members.change-role');

        $rows = $memberships->map(function (Membership $membership) use (
            $currentUser,
            $currentUserIsOwner,
            $activeOwnerCount,
            $openRecordsByUser,
            $canInvite,
            $canUpdateRoleAtAll,
        ): MembershipResource {
            $targetIsOwner = $membership->user?->hasRole(Permissions::OWNER) ?? false;

            $canDeactivate = $membership->isActive()
                && $currentUser->can('members.deactivate')
                && (! $targetIsOwner || $currentUserIsOwner)
                // P2-004 (review general) — auto-dezactivarea e blocată server-side
                // (`MembershipPolicy::deactivate()`); butonul dispare pe propriul rând,
                // ca orice altă acțiune fără drept (FR-RBAC-01).
                && $membership->user_id !== $currentUser->getKey()
                // §22.2 — conturile demo sunt login-uri partajate (raportul pachetului):
                // butonul dispare în DEMO_MODE, nu doar refuzat la submit.
                && DemoMode::allows('members.deactivate');

            $isLastActiveOwner = $targetIsOwner && $membership->isActive() && $activeOwnerCount <= 1;

            // BR-TEN-02 — un Manager gestionează Agent/Viewer și atât: pe rândul unui
            // Owner butonul LIPSEȘTE (FR-RBAC-01, „absent, nu dezactivat cu tooltip"),
            // nu apare și apoi refuză. Pe rândul propriu tot lipsește: o retrogradare de
            // sine ar fi, pentru ultimul Owner, chiar cazul blocat de BR-TEN-01, iar
            // pentru restul o cale de a-ți tăia singur accesul de pe ecranul ăsta.
            $canUpdateRole = $canUpdateRoleAtAll
                && $membership->isActive()
                && (! $targetIsOwner || $currentUserIsOwner)
                && $membership->user_id !== $currentUser->getKey();

            // Rolurile pe care ACEST utilizator le poate acorda (BR-TEN-02: „Owner" doar
            // pentru un Owner). Calculat server-side, ca select-ul din interfață să nu
            // conțină niciodată o opțiune care ar fi refuzată la submit (FR-RBAC-01).
            $assignableRoles = $currentUserIsOwner
                ? Permissions::roles()
                : array_values(array_diff(Permissions::roles(), [Permissions::OWNER]));

            $isPending = $membership->status === Membership::STATUS_PENDING;

            return new MembershipResource(
                $membership,
                canDeactivate: $canDeactivate,
                isLastActiveOwner: $isLastActiveOwner,
                openRecords: $openRecordsByUser[$membership->user_id] ?? ['deals' => 0, 'orders' => 0, 'total' => 0],
                canUpdateRole: $canUpdateRole,
                assignableRoles: $assignableRoles,
                canManageInvitation: $isPending && $canInvite && (! $targetIsOwner || $currentUserIsOwner),
            );
        })->values();

        // Opțiunile „Reassign to…" ale dialogului — membrii activi, ca la
        // Deals/Orders/Accounts (`ownerOptions()`); front-end-ul exclude local membrul
        // fiecărui rând din propria lui listă, ca un membru să nu-și poată alege propriile
        // înregistrări ca „noul owner" al lui însuși.
        $activeMembers = $memberships
            ->where('status', Membership::STATUS_ACTIVE)
            ->map(fn (Membership $membership) => ['id' => $membership->user_id, 'name' => $membership->user?->name])
            ->values();

        return Inertia::render('Settings/Members/Index', [
            'members' => $rows,
            'activeMembers' => $activeMembers,
            // BR-TEN-02 — lista de roluri a FORMULARULUI de invitare, calculată o singură
            // dată pentru pagină: un Manager nu vede deloc opțiunea „Owner", nu o vede și
            // primește refuz la submit.
            'invitableRoles' => $currentUserIsOwner
                ? Permissions::roles()
                : array_values(array_diff(Permissions::roles(), [Permissions::OWNER])),
            'can' => [
                'invite' => $canInvite,
                'updateRole' => $canUpdateRoleAtAll,
            ],
        ]);
    }

    /**
     * US-TEN-02 — „schimb rolul unui Manager în Viewer → modificarea e efectivă imediat și
     * înregistrată în activity_log (§17) cu valorile vechi și noi".
     *
     * Refuzul vine ca `withErrors()`, nu ca 403: aceeași motivare, verbatim, ca la
     * `deactivate()` mai jos — pe o cerere Inertia un 403 brut se citește ca „aplicație
     * stricată", iar clientul are nevoie de `page.props.errors` ca să distingă `onError`
     * de `onSuccess` și să țină dialogul deschis.
     */
    public function updateRole(UpdateMemberRoleRequest $request, Membership $membership): RedirectResponse
    {
        $newRole = $request->newRole();
        $memberName = $membership->user?->name ?? __('flash.members.fallback_name');

        $response = app(UpdateMemberRoleAction::class)->execute(
            actor: $request->user(),
            membership: $membership,
            newRole: $newRole,
            request: $request,
        );

        if ($response->denied()) {
            return back()->withErrors(['role' => $response->message()]);
        }

        // `:role` NU e tradus — numele rolului (Owner/Manager/Agent/Viewer) e etichetă de
        // domeniu, la fel ca `OrderStatus::label()`, dintr-un alt lot (vezi
        // `lang/en/flash.php`, comentariul de la `members.role_updated`).
        return redirect()
            ->route('settings.members.index')
            ->with('success', __('flash.members.role_updated', ['name' => $memberName, 'role' => $newRole]));
    }

    public function deactivate(DeactivateMembershipRequest $request, Membership $membership): RedirectResponse
    {
        $response = Gate::forUser($request->user())->inspect('deactivate', $membership);

        // BR-TEN-01 — blocare server-side, NU o excepție 403 opacă: pe o cerere Inertia
        // un 403 brut se randează ca „aplicație stricată" (motivul citat verbatim de
        // `EnsureDemoModeGuardrails`, același principiu aplicat aici pentru ultimul Owner).
        //
        // Audit de accesibilitate (P1) — `withErrors()`, NU `with('error', …)`: doar
        // primul populează `page.props.errors`, pe care clientul Inertia îl citește ca
        // să decidă `onError` vs `onSuccess`. Cu `with('error', …)` cererea era, din
        // perspectiva Inertia, un SUCCES fără erori — dialogul se închidea, iar mesajul
        // ajungea doar în banner-ul global `FlashMessages`, disociat de dialog. Cheia
        // `deactivate` nu ține de un câmp anume din formular (ultimul Owner, rol
        // insuficient) — afișată ca alertă generică în dialog, nu lângă un input.
        if ($response->denied()) {
            return back()->withErrors(['deactivate' => $response->message()]);
        }

        $targetUserId = $membership->user_id;
        $targetName = $membership->user?->name ?? __('flash.members.fallback_name');
        $counts = OpenRecordCounts::forUser($targetUserId);

        if ($request->shouldReassign()) {
            $newOwnerId = (string) $request->string('new_owner_user_id');
            $groupId = (string) Str::ulid();

            $this->authorize('bulkReassignOwner', Account::class);
            $this->authorize('bulkReassignOwner', Deal::class);
            $this->authorize('bulkReassignOwner', Order::class);

            $stillActive = DB::transaction(function () use ($request, $membership, $targetUserId, $newOwnerId, $groupId): bool {
                // P2-001 (review general) — blocare + reverificare SUB blocare, ÎNAINTEA
                // oricărei scrieri: verificarea Gate de mai sus a citit rândul înainte de
                // tranzacție, deci o a doua cerere concurentă (dublu-click, retry) putea
                // trece de ea și pe urmă dezactiva/reasigna a doua oară. Dacă blocarea
                // găsește rândul deja dezactivat, ieșim ÎNAINTE de a dispecera cele trei
                // operații — altfel am reasigna în masă pentru o dezactivare care n-a
                // apucat să se întâmple (din perspectiva ACESTEI cereri).
                if (! $this->lockAndApplyDeactivation($membership, $request)) {
                    return false;
                }

                $dispatch = app(DispatchBulkOperationAction::class);

                // BR-BULK-04 / plan §9 — trei operații, un `group_id` comun: conturile
                // nearhivate ale membrului, deals deschise, comenzi active (DECIZIE deja
                // luată, vezi raportul pachetului — nu doar deals+orders, spre deosebire
                // de vederea „Unassigned", care NU arată conturi).
                $this->dispatchReassignment(
                    $dispatch, $request->user(), 'accounts',
                    ['owner' => $targetUserId, 'status' => AccountList::STATUS_NOT_ARCHIVED],
                    $newOwnerId, $groupId,
                );
                $this->dispatchReassignment(
                    $dispatch, $request->user(), 'deals',
                    ['owner' => $targetUserId, 'status' => Deal::STATUS_OPEN],
                    $newOwnerId, $groupId,
                );
                $this->dispatchReassignment(
                    $dispatch, $request->user(), 'orders',
                    ['owner' => $targetUserId, 'status' => OrderList::STATUS_ACTIVE],
                    $newOwnerId, $groupId,
                );

                return true;
            });

            if (! $stillActive) {
                return back()->withErrors(['deactivate' => 'This member is already deactivated.']);
            }

            return redirect()
                ->route('bulk.groups.show', $groupId)
                ->with('success', __('flash.members.deactivating_with_reassignment', ['name' => $targetName]));
        }

        $stillActive = DB::transaction(fn () => $this->lockAndApplyDeactivation($membership, $request));

        if (! $stillActive) {
            return back()->withErrors(['deactivate' => 'This member is already deactivated.']);
        }

        if ($counts['total'] > 0) {
            Notification::send(
                ActiveOwners::forCurrentTenant(),
                new MembershipRecordsNeedNewOwnerNotification(
                    deactivatedMemberName: $targetName,
                    tenantName: app('tenant')->name,
                    workspaceSlug: app('tenant')->slug,
                    openDeals: $counts['deals'],
                    activeOrders: $counts['orders'],
                ),
            );
        }

        // Capcana centrală a lotului (vezi docblock-ul `lang/en/flash.php`): „0 records"
        // e plural în engleză, dar SINGULAR în franceză — `trans_choice()`, nu un ternar
        // pe `> 0`/`Str::plural()` manual.
        $message = $counts['total'] > 0
            ? trans_choice('flash.members.deactivated_with_open_records', $counts['total'], [
                'name' => $targetName,
                'count' => $counts['total'],
            ])
            : __('flash.members.deactivated', ['name' => $targetName]);

        return redirect()->route('settings.members.index')->with('success', $message);
    }

    /**
     * @param  array<string, string>  $filters
     */
    private function dispatchReassignment(
        DispatchBulkOperationAction $dispatch,
        User $actor,
        string $resourceType,
        array $filters,
        string $newOwnerId,
        string $groupId,
    ): void {
        $resource = BulkWritableResources::resolve($resourceType);
        $list = app($resource->listClass());
        $listQuery = $list->fromState(['filter' => $filters]);

        $dispatch->execute(
            user: $actor,
            resource: $resource,
            action: BulkChunkActions::REASSIGN_OWNER,
            listQuery: $listQuery,
            ids: null,
            actionPayload: ['owner_user_id' => $newOwnerId],
            groupId: $groupId,
            // BR-TEN-06 — dialogul de confirmare DIN INTERFAȚĂ e confirmarea (numărul
            // exact, afișat înainte de „Reassign and deactivate"): a doua confirmare, la
            // pragul FR-BULK-01, ar fi un al doilea dialog pentru aceeași decizie.
            confirmed: true,
        );
    }

    /**
     * Efectul dezactivării (BR-TEN-04) + jurnalul de activitate (§17, valorile vechi/noi),
     * SUB blocarea rândului propriu-zis (`lockForUpdate()`, acceptabil pe rândul chiar
     * modificat — tenancy.md) — nu pe rândul din parametru, adus ÎNAINTE de tranzacție de
     * legarea rutei, care poate fi deja STALE față de o cerere concurentă.
     *
     * P2-001 (review general) — reverifică `isActive()` SUB blocare și nu scrie nimic
     * dacă rândul a fost deja dezactivat între timp (idempotență contra dublu-click,
     * retry, două cereri concurente): fără reverificare, a doua cerere ar rescrie
     * `deactivated_at`/`deactivated_by` cu date noi și ar scrie în `activity_log` un
     * `old_values.status = active` FALS. Apelat ÎN interiorul tranzacției apelantului —
     * nu deschide una proprie.
     *
     * @return bool `false` = rândul era deja dezactivat sub blocare; apelantul nu mai
     *              scrie nimic altceva (nicio reasignare, nicio notificare).
     */
    private function lockAndApplyDeactivation(Membership $membership, Request $request): bool
    {
        $locked = Membership::query()->whereKey($membership->getKey())->lockForUpdate()->first();

        if ($locked === null || ! $locked->isActive()) {
            return false;
        }

        $locked->update([
            'status' => Membership::STATUS_DEACTIVATED,
            'deactivated_at' => now(),
            'deactivated_by' => $request->user()->getKey(),
        ]);

        // FR-TEN-04 — invalidare explicită: fără asta, un `DeactivatedMemberIds` deja
        // populat mai devreme ÎN ACEEAȘI cerere/job (tenantul curent) ar continua să
        // arate membrul ca activ până la următoarea cerere.
        app(DeactivatedMemberIds::class)->forgetCurrentTenant();

        ActivityLog::query()->create([
            'user_id' => $request->user()->getKey(),
            'action' => 'updated',
            'auditable_type' => Membership::class,
            'auditable_id' => $locked->getKey(),
            'old_values' => ['status' => Membership::STATUS_ACTIVE],
            'new_values' => ['status' => Membership::STATUS_DEACTIVATED, 'deactivated_by' => $request->user()->getKey()],
            'ip_address' => (string) $request->ip(),
            'user_agent' => (string) $request->userAgent(),
        ]);

        return true;
    }
}
