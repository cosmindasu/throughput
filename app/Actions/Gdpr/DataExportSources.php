<?php

namespace App\Actions\Gdpr;

use App\Models\Account;
use App\Models\ActivityLog;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use InvalidArgumentException;

/**
 * Cele șapte entități cerute literal de FR-GDPR-01 / plan §11: „accounts, contacts, deals,
 * orders, invoices, payments, activity_log ale tenantului curent". Sursă unică — jobul
 * planificator construiește câte un job de batch per intrare, jobul de entitate scrie
 * fișierele, iar `manifest.json` descrie exact această listă.
 *
 * **Ce primește și CSV** (specs.md §20.5: „CSV pentru tabelele plate mari (ex: comenzi)"):
 * sursele ale căror coloane proprii sunt toate scalare. `accounts` (adresele și etichetele
 * sunt `jsonb`) și `activity_log` (diff-urile `old_values`/`new_values` sunt `jsonb`) rămân
 * doar JSON — un CSV le-ar aplatiza în șiruri, adică exact pierderea de structură pe care
 * formatul „structurat, citit automat" o interzice. JSON-ul e livrat pentru TOATE cele
 * șapte, deci nimeni nu pierde nimic prin lipsa unui CSV.
 *
 * **Niciun PDF** (plan §11, decizie explicită): arhiva conține exclusiv JSON și CSV.
 *
 * FR-I18N-04, Lotul I18N Val 5 (a treia trecere) — `label`/`note` treceau prin `lang/gdpr.php`
 * abia acum: ambele ajung DOAR în `manifest.json`, deci gate-ul `i18n:coverage` (care
 * compară cataloagele între ele) n-avea cum să vadă că engleza nu ajunsese niciodată în
 * catalog. Randate în limba celui care a CERUT exportul — `__()` citește `App::getLocale()`,
 * setat EXPLICIT la începutul lui `App\Jobs\Gdpr\ExportTenantEntityJob::handle()` (unde
 * `self::all()` se evaluează efectiv, prin `DataExportSources::resolve()`), nu doar al lui
 * `FinalizeDataExportJob::handle()` — vezi docblock-ul jobului de entitate pentru motivul
 * exact (worker de coadă de viață lungă, `.ai/rules/tenancy.md:123-138`).
 */
final class DataExportSources
{
    /**
     * @return list<DataExportSource>
     */
    public static function all(): array
    {
        return [
            new DataExportSource(
                name: 'accounts',
                label: __('gdpr.sources.accounts.label'),
                modelClass: Account::class,
                with: [],
                csv: false,
                note: __('gdpr.sources.accounts.note'),
            ),
            new DataExportSource(
                name: 'contacts',
                label: __('gdpr.sources.contacts.label'),
                modelClass: Contact::class,
                with: [],
                csv: true,
                note: __('gdpr.sources.contacts.note'),
            ),
            new DataExportSource(
                name: 'deals',
                label: __('gdpr.sources.deals.label'),
                modelClass: Deal::class,
                with: [],
                csv: true,
                note: __('gdpr.sources.deals.note'),
            ),
            new DataExportSource(
                name: 'orders',
                label: __('gdpr.sources.orders.label'),
                modelClass: Order::class,
                with: ['orderLines'],
                csv: true,
                note: __('gdpr.sources.orders.note'),
            ),
            new DataExportSource(
                name: 'invoices',
                label: __('gdpr.sources.invoices.label'),
                modelClass: Invoice::class,
                with: [],
                csv: true,
                note: __('gdpr.sources.invoices.note'),
            ),
            new DataExportSource(
                name: 'payments',
                label: __('gdpr.sources.payments.label'),
                modelClass: Payment::class,
                with: [],
                csv: true,
                note: __('gdpr.sources.payments.note'),
            ),
            new DataExportSource(
                name: 'activity_log',
                label: __('gdpr.sources.activity_log.label'),
                modelClass: ActivityLog::class,
                with: [],
                csv: false,
                note: __('gdpr.sources.activity_log.note'),
            ),
        ];
    }

    public static function resolve(string $name): DataExportSource
    {
        foreach (self::all() as $source) {
            if ($source->name === $name) {
                return $source;
            }
        }

        throw new InvalidArgumentException("Unknown data export source [{$name}].");
    }

    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return array_map(static fn (DataExportSource $source): string => $source->name, self::all());
    }
}
