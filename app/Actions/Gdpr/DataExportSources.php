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
                label: 'Accounts',
                modelClass: Account::class,
                with: [],
                csv: false,
                note: 'Every company record in this workspace. Billing address, shipping address and tags are structured objects, which is why this entity is JSON only — a spreadsheet column would have flattened them into text.',
            ),
            new DataExportSource(
                name: 'contacts',
                label: 'Contacts',
                modelClass: Contact::class,
                with: [],
                csv: true,
                note: 'Every person recorded against an account. Contacts that were anonymised under the right to erasure are not here: their identifying fields were already cleared, so the row that remains carries no personal data to hand over.',
            ),
            new DataExportSource(
                name: 'deals',
                label: 'Deals',
                modelClass: Deal::class,
                with: [],
                csv: true,
                note: 'Every deal, including the ones that were deleted from the board: a deleted deal is still stored, so it is still data held about you. Those rows carry a "deleted_at" date; the live ones have it empty.',
            ),
            new DataExportSource(
                name: 'orders',
                label: 'Orders',
                modelClass: Order::class,
                with: ['orderLines'],
                csv: true,
                note: 'Every order, with its lines nested under "order_lines" in the JSON file. The CSV holds the order rows only — one row per order, without the lines, because a line-per-row table would repeat every order total.',
            ),
            new DataExportSource(
                name: 'invoices',
                label: 'Invoices',
                modelClass: Invoice::class,
                with: [],
                csv: true,
                note: 'Every invoice raised against an order. The generated PDF itself is not in the archive — it is a rendering of these same figures, and a PDF does not count as a machine-readable format for portability.',
            ),
            new DataExportSource(
                name: 'payments',
                label: 'Payments',
                modelClass: Payment::class,
                with: [],
                csv: true,
                note: 'Every payment recorded against an invoice. Payments are entered by hand in this product, so there is no card or bank data of any kind to export.',
            ),
            new DataExportSource(
                name: 'activity_log',
                label: 'Activity log',
                modelClass: ActivityLog::class,
                with: [],
                csv: false,
                note: 'Who changed what, and when. Entries older than the retention window have their before/after values replaced with "[anonymized]" — the shape of the entry survives, the values do not. JSON only, because those before/after values are structured objects.',
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
