<?php

namespace App\Support\Activity;

use App\Models\Account;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Product;
use App\Models\Variant;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * FR-AUD-02, §17.3 — harta alias de URL → model, pentru tab-ul „History" montat pe
 * paginile de detaliu ale entităților EXISTENTE azi. Mirror-ul lui `App\Support\Bulk\
 * BulkWritableResources`/`App\Support\Exports\ExportableResources`: un singur loc, ca
 * `App\Http\Controllers\Web\ActivityLogController::forEntity()` și componenta React
 * `resources/js/Components/History/HistoryTab.tsx` să vorbească despre aceleași alias-uri
 * (`account`, `contact`, `deal`, `product`, `variant`, `order`), nicăieri altundeva scrise
 * din memorie.
 *
 * Aceeași listă ca modelele observate de `App\Providers\ActivityLogServiceProvider` — dar
 * NU e același lucru: a fi în harta asta înseamnă „poate avea un tab History", nu „e
 * observat". Cele două liste sunt deliberat identice azi; dacă vreodată diverg (o entitate
 * cu tab de istoric dar scrisă manual în `activity_log`, ca `Membership` azi), fiecare are
 * propriul motiv de a exista.
 */
final class AuditableResources
{
    /** @return array<string, class-string<Model>> */
    public static function map(): array
    {
        return [
            'account' => Account::class,
            'contact' => Contact::class,
            'deal' => Deal::class,
            'product' => Product::class,
            'variant' => Variant::class,
            'order' => Order::class,
            'invoice' => Invoice::class,
        ];
    }

    /** @return class-string<Model> */
    public static function resolve(string $type): string
    {
        $class = self::map()[$type] ?? null;

        if ($class === null) {
            throw new InvalidArgumentException("Tipul de entitate \"{$type}\" nu are un tab de istoric înregistrat.");
        }

        return $class;
    }
}
