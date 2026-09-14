<?php

namespace App\Policies;

use App\Models\Deal;
use App\Models\User;
use App\Support\Permissions;

/**
 * Matricea §7.4, rândul „Deals", plus îngustarea ABAC din §7.5 și cerințele Pachetului C.
 *
 * Diferă deliberat de `AccountPolicy::isWithinOwnRecords()`: aici contează DOAR
 * `owner_user_id`, nu și `created_by` — un deal creat de un Agent îl face automat owner
 * (`CreateDealAction`), deci a doua condiție n-ar acoperi niciun caz în plus, doar
 * ambiguitate suplimentară.
 *
 * Abatere semnalată explicit (cerută de task): §7.5 pune verificarea „Won fără valoare"
 * în `DealPolicy::moveStage()`. NU e aici — e o regulă de STARE a deal-ului (are sau nu
 * o valoare), nu un DREPT al utilizatorului peste resursă. Un Policy răspunde la „poate
 * omul ăsta muta ORICE deal pe ORICE etapă", nu la „e etapa asta validă pentru ACEST deal
 * ACUM". Amestecate, un 403 opac ar înlocui mesajul explicit „Set a deal value before
 * marking as Won" cerut de criteriul de acceptanță §9.3 — exact „aplicația stricată" din
 * §7.3. Regula trăiește în `App\Actions\Deals\MoveDealStageAction`, ca `ValidationException`.
 */
class DealPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('deals.view');
    }

    public function view(User $user, Deal $deal): bool
    {
        return $user->can('deals.view');
    }

    public function create(User $user): bool
    {
        return $user->can('deals.create');
    }

    public function update(User $user, Deal $deal): bool
    {
        return $user->can('deals.edit') && $this->isWithinOwnRecords($user, $deal);
    }

    public function delete(User $user, Deal $deal): bool
    {
        return $user->can('deals.delete') && $this->isWithinOwnRecords($user, $deal);
    }

    public function moveStage(User $user, Deal $deal): bool
    {
        return $user->can('deals.move_stage') && $this->isWithinOwnRecords($user, $deal);
    }

    /**
     * Doar permisiunea — nicio îngustare ABAC: în matricea de roluri (`Permissions::forRoles()`)
     * `deals.change_owner` există doar la Owner/Manager, niciodată la Agent, deci
     * proprietatea curentă a deal-ului n-are cum să schimbe rezultatul.
     *
     * `?Deal $deal = null`: Gate::authorize('changeOwner', Deal::class) (pagina Create,
     * unde încă nu există niciun deal) scurtează argumentul la un singur parametru
     * (`$user`) — vezi `Gate::callPolicyMethod()`. Aceeași metodă acoperă și
     * Show/Edit, unde se cheamă cu instanța reală.
     */
    public function changeOwner(User $user, ?Deal $deal = null): bool
    {
        return $user->can('deals.change_owner');
    }

    /**
     * §13.4 (Pachetul C, valul „bulk") — reasignare owner în masă pe Deals. Legată de
     * `deals.change_owner`, ca `changeOwner()` de mai sus: Agent nu are permisiunea asta în
     * catalog (`Permissions::forRoles()`), deci nu poate reasigna owner-ul unui deal, nici
     * individual, nici în masă — nesimetric față de Accounts (unde Agentul poate, pe
     * subsetul propriu), decizie DEJA existentă în catalogul de roluri, nu una nouă a
     * acestui pachet.
     */
    public function bulkReassignOwner(User $user): bool
    {
        return $user->can('deals.change_owner') && $user->can('bulk.write');
    }

    /**
     * §7.5: un Agent lucrează doar pe deal-urile unde e responsabil.
     */
    private function isWithinOwnRecords(User $user, Deal $deal): bool
    {
        if (! Permissions::restrictedToOwnRecords($user)) {
            return true;
        }

        return $deal->owner_user_id === $user->getKey();
    }
}
