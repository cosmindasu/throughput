<?php

namespace App\Support\Lists;

use App\Models\User;
use App\Support\ListQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Definiția unei liste: ce filtre acceptă, după ce se poate sorta și cum se traduc filtrele
 * în SQL.
 *
 * Lista web, exportul și vizualizările salvate trec toate prin `query()`, deci văd aceleași
 * rânduri pentru același URL. Izolarea de tenant NU e treaba acestei clase: global scope-ul
 * și RLS se aplică deja pe `baseQuery()`, iar un `where tenant_id` scris aici ar fi un al
 * treilea strat, care poate greși independent de celelalte două.
 */
abstract class ResourceList
{
    /** @return list<string> */
    abstract protected function filterKeys(): array;

    /** @return list<string> */
    abstract protected function sortableColumns(): array;

    abstract protected function defaultSort(): string;

    abstract protected function baseQuery(): Builder;

    abstract protected function applyFilters(Builder $query, ListQuery $list, User $user): void;

    /**
     * @return array<string, string>
     */
    protected function defaultFilters(User $user): array
    {
        return [];
    }

    /**
     * Cheile de filtru al căror IMPLICIT depinde de ROLUL utilizatorului (ex: `owner` pe
     * Accounts/Deals — Agentul pornește pe „My accounts/deals", Owner/Manager pe „All"),
     * NU de valoarea implicitului pentru un rol anume — o listă fără nicio îngustare de rol
     * (ex: Contacts) întoarce `[]`. Vezi `pinRoleDependentFiltersForSharing()`, singurul loc
     * care citește această listă (P2-004, code review).
     *
     * @return list<string>
     */
    protected function roleDependentFilterKeys(): array
    {
        return [];
    }

    /**
     * P2-004 (code review) — o vizualizare salvată reproduce scopul de owner EFECTIV al
     * AUTORULUI ei, pentru ORICINE o deschide mai târziu, nu implicitul rolului celui care o
     * deschide. Fără asta: Managerul salvează „All accounts" (implicitul lui — `owner`
     * ABSENT din `list.filter`, `AccountList::defaultFilters()` întoarce `[]` pentru un rol
     * nerestrâns), un Agent deschide linkul, iar `ListQuery::fromRequest()` — care NU repetă
     * implicitul autorului, ci al celui care cere pagina — îi aplică PROPRIUL implicit
     * (`owner=me`), deci Agentul vede doar conturile lui, nu ce a văzut Managerul. Contrazice
     * direct plan §8 („redeschisă cu filtrele… intacte") și glosarul din specs.md
     * („combinație numită de filtre… partajabilă").
     *
     * Regula: o cheie din `roleDependentFilterKeys()` ABSENTĂ din starea curentă a autorului
     * (dovadă că autorul NU era restrâns de rol) se fixează explicit ca `'all'` — convenția
     * comună `owner`-ului pe `AccountList`/`DealList`, singurele liste care suprascriu
     * `roleDependentFilterKeys()` azi. O cheie DEJA prezentă (ex: un Agent salvează cu
     * `owner=me`, propriul lui implicit, umplut de `ListQuery::fromRequest()` chiar dacă
     * URL-ul nu-l arată explicit) rămâne NEATINSĂ: „me" rămâne relativ la cine DESCHIDE
     * vederea, nu la autor — comportament deja existent, intenționat, nu o ambiguitate de
     * rezolvat aici (un Agent care trimite propriul link altui Agent se așteaptă ca fiecare
     * să vadă „ale lui", nu „ale primului Agent").
     *
     * Aplicată o singură dată, la SALVARE (`SavedViewController::store()`) — nu la fiecare
     * `apply()`: vederile deja salvate înainte de acest fix nu se migrează (datele demo se
     * resetează noaptea oricum), dar orice vedere NOUĂ e corectă de la creare.
     *
     * @param  array<string, string>  $filters
     * @return array<string, string>
     */
    public function pinRoleDependentFiltersForSharing(array $filters): array
    {
        foreach ($this->roleDependentFilterKeys() as $key) {
            $filters[$key] ??= 'all';
        }

        return $filters;
    }

    protected function accepts(string $key, string $value): bool
    {
        return true;
    }

    public function parse(Request $request): ListQuery
    {
        return ListQuery::fromRequest(
            $request,
            $this->filterKeys(),
            $this->sortableColumns(),
            $this->defaultSort(),
            $this->defaultFilters($request->user()),
            $this->accepts(...),
        );
    }

    /**
     * Reface un `ListQuery` dintr-un `filter_snapshot` de `bulk_operations` — exportul în
     * coadă (§13.2) reia exact interogarea capturată la declanșare, fără cerere HTTP.
     *
     * @param  array{filter?: array<string, mixed>, sort?: mixed}  $state
     */
    public function fromState(array $state): ListQuery
    {
        return ListQuery::fromState(
            $state,
            $this->filterKeys(),
            $this->sortableColumns(),
            $this->defaultSort(),
            $this->accepts(...),
        );
    }

    public function query(ListQuery $list, User $user): Builder
    {
        $query = $this->baseQuery();

        $this->applyFilters($query, $list, $user);

        return $list->applySort($query);
    }
}
