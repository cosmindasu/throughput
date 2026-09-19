<?php

namespace App\Support\Members;

use App\Models\Membership;

/**
 * Cache-ul efectiv din spatele `DeactivatedMemberNames` — o SINGURĂ instanță `scoped()`
 * (înregistrată în `AppServiceProvider`), cu rezultatele ținute în PROPRIETATEA
 * instanței, cheiate pe `tenant_id`, NU într-un binding de container separat capturat
 * într-o închidere.
 *
 * Bug găsit de coordonator, nu la review: varianta anterioară lega un binding de
 * container cu `app()->scoped($cheie, fn () => $ids)` — o închidere care CAPTUREAZĂ
 * `$ids` din prima rezolvare. Laravel cheamă `forgetScopedInstances()` doar în
 * worker-ul de coadă, ÎNAINTE de fiecare job (`QueueServiceProvider`), și acolo golește
 * DOAR instanța rezolvată a binding-ului `DeactivatedMemberIds::class` însuși (obiectul
 * ăsta e aruncat și recreat) — nu mai există nicio închidere veche care să supraviețuiască,
 * fiindcă cache-ul stă ÎN obiect, nu într-o valoare capturată separat. Pe un worker
 * Horizon care procesează joburi pentru tenanți diferiți succesiv, varianta veche arăta
 * setul PRIMULUI tenant tuturor celor de după — un membru dezactivat în tenantul B nu
 * apărea „(deactivated)" dacă tenantul A fusese procesat primul, iar un membru activ în B
 * putea apărea greșit „(deactivated)" dacă avea același user_id dezactivat în A.
 */
final class DeactivatedMemberIds
{
    /** @var array<string, list<string>> */
    private array $cache = [];

    /**
     * P3 (review general) — FĂRĂ parametru de tenant: cel de dinainte era doar o CHEIE
     * de cache, nu un filtru transmis interogării (`Membership::query()` se scopează
     * singur, prin global scope + RLS, pe contextul curent) — un apelant care ar fi
     * trecut din greșeală un alt id ar fi CITIT datele tenantului curent, dar le-ar fi
     * pus sub cheia GREȘITĂ, o divergență imposibil de observat local. Tenantul vine de
     * aici, dintr-un singur loc, la fel ca interogarea însăși.
     *
     * @return list<string>
     */
    public function forCurrentTenant(): array
    {
        if (! app()->bound('tenant')) {
            return [];
        }

        $tenantId = app('tenant')->getKey();

        return $this->cache[$tenantId] ??= Membership::query()
            ->where('status', Membership::STATUS_DEACTIVATED)
            ->pluck('user_id')
            ->all();
    }

    /**
     * Invalidare explicită — apelată imediat după orice scriere care schimbă
     * `memberships.status` (`MembersController::lockAndApplyDeactivation()`), ca ACEEAȘI
     * cerere/job să nu mai citească starea veche dintr-un cache deja populat mai
     * devreme în același proces.
     */
    public function forgetCurrentTenant(): void
    {
        if (! app()->bound('tenant')) {
            return;
        }

        unset($this->cache[app('tenant')->getKey()]);
    }
}
