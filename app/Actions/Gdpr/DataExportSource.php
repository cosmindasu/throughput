<?php

namespace App\Actions\Gdpr;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * O entitate din arhiva de export (FR-GDPR-01) — modelul, ce relații copil se scriu
 * imbricat, dacă primește și un CSV, și nota care explică în `manifest.json` ce conține
 * fișierul și, mai important, ce NU conține.
 *
 * `newQuery()` nu adaugă niciun `where tenant_id` (plan §11, specs.md §20.5: „izolare
 * identică cu restul aplicației, fără cod suplimentar de filtrare"): stratul 1 (global
 * scope `BelongsToTenant`) și stratul 2 (RLS, ADR-003) o fac amândouă. Un `where` în plus
 * aici ar masca tocmai scurgerea pe care testele de izolare o caută.
 */
final class DataExportSource
{
    /**
     * @param  class-string<Model>  $modelClass
     * @param  list<string>  $with  relații copil, scrise IMBRICAT în JSON (nu și în CSV)
     */
    public function __construct(
        public readonly string $name,
        public readonly string $label,
        public readonly string $modelClass,
        public readonly array $with,
        public readonly bool $csv,
        public readonly string $note,
    ) {}

    public function newQuery(): Builder
    {
        $query = $this->modelClass::query();

        if ($this->with !== []) {
            $query->with($this->with);
        }

        // Un rând șters logic e un rând pe care operatorul încă îl PĂSTREAZĂ, deci intră în
        // răspunsul la o cerere de acces/portabilitate (Art. 15/20) — `deleted_at` e în
        // payload, deci nimic nu devine ambiguu. Singurul model cu `SoftDeletes` dintre
        // cele șapte e `Deal`; nota din manifest o spune explicit, ca omisiunea inversă să
        // nu fie nevoie s-o deducă nimeni.
        if (in_array(SoftDeletes::class, class_uses_recursive($this->modelClass), true)) {
            $query->withTrashed();
        }

        return $query;
    }

    public function table(): string
    {
        return (new $this->modelClass)->getTable();
    }
}
