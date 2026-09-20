<?php

namespace App\Observers;

use App\Events\Activity\ModelWasRecorded;
use App\Models\Scopes\TenantScope;
use App\Support\Activity\ChangedAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * ADR-007, specs.md §17 — un singur observer generic, ATAȘAT de `App\Providers\
 * ActivityLogServiceProvider` pe fiecare model de business relevant (Account, Contact,
 * Deal, Product, Variant, Order — vezi docblock-ul provider-ului pentru lista completă și
 * motivul ei), în loc de un observer PER model: cele trei metode de mai jos nu depind de
 * NIMIC specific unui model anume, deci o clasă per model ar fi fost cod repetat fără
 * niciun beneficiu — modelele rămân neatinse (niciun `#[ObservedBy]`, vezi motivul din
 * raportul lotului: `app/Models/Invoice.php` etc. sunt editate ÎN PARALEL de alte loturi).
 *
 * Rulează SINCRON, în firul cererii — de aici captura de `request()->ip()`/`userAgent()`
 * AICI, nu în listener (care rulează pe coadă, unde cererea originală nu mai există).
 * Singurul lucru pe care acest observer îl face e să CONSTRUIASCĂ evenimentul cu scalari
 * și să-l dispecerizeze; scrierea propriu-zisă e a listener-ului pe coadă
 * (`App\Listeners\Activity\WriteActivityLogEntry`), per ADR-007.
 */
final class ActivityLogObserver
{
    public function created(Model $model): void
    {
        $this->record($model, 'created', null, ChangedAttributes::snapshot($model->getAttributes()));
    }

    public function updated(Model $model): void
    {
        $changedKeys = array_keys($model->getChanges());

        if ($changedKeys === []) {
            return;
        }

        // `only()`, NU `getChanges()`/`getOriginal()` direct: `only()` trece prin
        // `getAttribute()`, deci aplică CAST-urile modelului (`decimal:2` etc.) — găsit prin
        // test: `getChanges()` întoarce valoarea BRUTĂ, exact cum a fost atribuită
        // (`update(['price' => 12.5])` rămâne float `12.5`, nu `"12.50"`), în timp ce
        // `getOriginal()` reflectă ce a întors PDO la ultima citire (deja `"10.00"`, format
        // Postgres) — un diff neschimbat ar arăta „10.00 → 12.5", inconsecvent, pe orice
        // coloană `decimal`/`array`. O instanță „veche" temporară, cu atributele originale,
        // aplică ACELEAȘI cast-uri pentru ambele părți ale diff-ului.
        $newValues = $model->only($changedKeys);
        $oldValues = (new ($model::class))->setRawAttributes($model->getOriginal())->only($changedKeys);

        [$old, $new] = ChangedAttributes::fromEloquentUpdate($oldValues, $newValues);

        // Doar `updated_at` (sau alte coloane tehnice) s-a atins — nimic demn de jurnal
        // (§17.1: „doar câmpurile modificate"). Un `touch()` fără modificare de business
        // nu are ce căuta în audit.
        if ($old === null && $new === null) {
            return;
        }

        $this->record($model, 'updated', $old, $new);
    }

    public function deleted(Model $model): void
    {
        $this->record($model, 'deleted', ChangedAttributes::snapshot($model->getAttributes()), null);
    }

    /**
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     */
    private function record(Model $model, string $action, ?array $oldValues, ?array $newValues): void
    {
        $tenantId = TenantScope::currentTenantId();

        // Fără context de tenant, nu există unde să scrie rândul (RLS respinge oricum
        // INSERT-ul, `.ai/rules/tenancy.md`) — nu ar trebui să se întâmple pentru modelele
        // observate (toate trăiesc sub grupul `{workspace}`), dar un observer care ar
        // arunca aici ar transforma o lipsă de context într-un 500 pe o operație de
        // business complet validă. Mai sigur: jurnalul lipsește, restul cererii continuă.
        if ($tenantId === null) {
            return;
        }

        ModelWasRecorded::dispatch(
            $tenantId,
            Auth::id(),
            $action,
            $model::class,
            (string) $model->getKey(),
            $oldValues,
            $newValues,
            (string) request()?->ip(),
            (string) request()?->userAgent(),
        );
    }
}
