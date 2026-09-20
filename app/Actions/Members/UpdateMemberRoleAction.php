<?php

namespace App\Actions\Members;

use App\Models\ActivityLog;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\PermissionRegistrar;

/**
 * US-TEN-02 — „schimb rolul unui Manager în Viewer → modificarea e efectivă imediat și
 * înregistrată în activity_log (§17) cu valorile vechi și noi".
 *
 * Regulile de DREPT (BR-TEN-01: nu ultimul Owner; BR-TEN-02: doar un Owner atinge un Owner)
 * stau în `MembershipPolicy::updateRole()`, nu aici — acțiunea le CHEAMĂ, o a doua oară,
 * sub blocare.
 */
final class UpdateMemberRoleAction
{
    /**
     * @return Response `denied()` = refuz de regulă (mesaj pentru dialog), `allow()` = aplicat
     */
    public function execute(User $actor, Membership $membership, string $newRole, Request $request): Response
    {
        return DB::transaction(function () use ($actor, $membership, $newRole, $request): Response {
            // BR-TEN-01 e o invariantă de TENANT („minim un Owner activ"), nu una care
            // trăiește pe rândul modificat: două retrogradări concurente, fiecare validă
            // singură, pot lăsa workspace-ul fără niciun Owner. Se serializează blocând
            // rândul PĂRINTE, iar `for no key update` — nu `lockForUpdate()` — fiindcă
            // `FOR UPDATE` pe `tenants` intră în conflict cu `FOR KEY SHARE`, blocarea pe
            // care PostgreSQL o ia la verificarea FK a oricărui INSERT în orice tabelă
            // copil: ar fi oprit scrierile ÎNTREGULUI tenant până la finalul cererii
            // (`.ai/rules/tenancy.md`, măsurat cu două sesiuni).
            Tenant::query()->whereKey($membership->tenant_id)->lock('for no key update')->first();

            $locked = Membership::query()->whereKey($membership->getKey())->lockForUpdate()->with('user')->first();

            if ($locked === null) {
                return Response::deny('This member no longer exists in this workspace.');
            }

            $registrar = app(PermissionRegistrar::class);
            $registrar->setPermissionsTeamId($locked->tenant_id);

            $user = $locked->user;

            if ($user === null) {
                return Response::deny('This member no longer exists in this workspace.');
            }

            // Relația de roluri poate fi deja încărcată cu starea de dinainte de blocare.
            $user->unsetRelation('roles');
            $currentRole = (string) $user->getRoleNames()->first();

            if ($currentRole === $newRole) {
                // Nu o eroare: rezultatul cerut e deja adevărat. Dar nici un rând de jurnal
                // „a schimbat rolul din Agent în Agent", care ar polua §17 cu zgomot.
                return Response::allow();
            }

            $decision = Gate::forUser($actor)->inspect('updateRole', [$locked, $newRole]);

            if ($decision->denied()) {
                return $decision;
            }

            $user->syncRoles([$newRole]);

            ActivityLog::query()->create([
                'user_id' => $actor->getKey(),
                // Valoare dedicată în enum-ul coloanei (`ActivityLog::ACTIONS`) — nu
                // `updated`, care e deja folosit de dezactivare pe același `auditable_type`.
                'action' => 'role_changed',
                'auditable_type' => Membership::class,
                'auditable_id' => $locked->getKey(),
                'old_values' => ['role' => $currentRole],
                'new_values' => ['role' => $newRole],
                'ip_address' => (string) $request->ip(),
                'user_agent' => (string) $request->userAgent(),
            ]);

            return Response::allow();
        });
    }
}
