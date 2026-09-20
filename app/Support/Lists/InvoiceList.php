<?php

namespace App\Support\Lists;

use App\Models\Invoice;
use App\Models\User;
use App\Support\Exports\ArchivableList;
use App\Support\Exports\ExportableList;
use App\Support\ListQuery;
use App\Support\Permissions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Lista de facturi — §12.1, „listă Invoices (paginare pe cursor, filtre ca la Orders)".
 * Imită `OrderList` (plan §9 task 1) — aceeași bază `ResourceList`.
 *
 * FR-BILL-03 („export facturi în masă: PDF zip sau CSV sumar"), amânat de lotul care a
 * construit lista, e livrat de Faza 5, valul 2: de aici cele DOUĂ contracte de export.
 * `ExportableList` dă CSV-ul sumar (aceleași coloane ca ecranul, prin mecanismul comun din
 * §13.2), `ArchivableList` dă arhiva ZIP a PDF-urilor deja generate per factură. Nimic
 * specific exportului nu e reimplementat aici — doar forma rândului și numele fișierului.
 */
final class InvoiceList extends ResourceList implements ArchivableList, ExportableList
{
    /** @return list<string> */
    protected function filterKeys(): array
    {
        return ['q', 'status', 'account', 'from', 'to'];
    }

    protected function sortableColumns(): array
    {
        return ['invoice_number', 'total', 'balance_due', 'due_date', 'created_at'];
    }

    protected function defaultSort(): string
    {
        return '-created_at';
    }

    protected function accepts(string $key, string $value): bool
    {
        return match ($key) {
            'status' => in_array($value, [
                Invoice::STATUS_DRAFT, Invoice::STATUS_SENT, Invoice::STATUS_PAID,
                Invoice::STATUS_OVERDUE, Invoice::STATUS_VOID,
            ], true),
            'account' => Str::isUlid($value),
            'from', 'to' => strtotime($value) !== false,
            default => true,
        };
    }

    protected function baseQuery(): Builder
    {
        return Invoice::query()->with(['order:id,order_number,account_id,owner_user_id', 'order.account:id,name']);
    }

    /**
     * §7.4, rândul „Facturi (către clienți)", litera „R*" la Agent — spre deosebire de
     * `OrderList` (unde Agentul alege între „My orders"/„All orders"), aici restricția
     * e NECONDIȚIONATĂ: matricea nu-i dă Agentului un comutator, doar vederea îngustă.
     */
    protected function applyFilters(Builder $query, ListQuery $list, User $user): void
    {
        if (Permissions::restrictedToOwnRecords($user)) {
            $query->whereHas('order', fn (Builder $orders) => $orders->where('owner_user_id', $user->getKey()));
        }

        if (($search = $list->filter('q')) !== null) {
            $query->where('invoice_number', 'ilike', '%'.addcslashes($search, '%_\\').'%');
        }

        if (($status = $list->filter('status')) !== null) {
            $query->where('status', $status);
        }

        if (($account = $list->filter('account')) !== null) {
            $query->whereHas('order', fn (Builder $orders) => $orders->where('account_id', $account));
        }

        if (($from = $list->filter('from')) !== null) {
            $query->whereDate('created_at', '>=', $from);
        }

        if (($to = $list->filter('to')) !== null) {
            $query->whereDate('created_at', '<=', $to);
        }
    }

    /**
     * FR-BILL-03, „CSV sumar" — coloanele ecranului, plus cele două care lipsesc din tabel
     * dar sunt exact ce caută cineva într-un sumar de facturare (data emiterii, cât s-a
     * încasat). Fără `pdf_path`: e o cale internă de stocare, fără sens pentru cititor.
     *
     * @return list<string>
     */
    public function exportHeaders(): array
    {
        // FR-I18N-04 — vezi nota din `AccountList::exportHeaders()`. Facturile nu se
        // importă, deci aici nu există constrângerea de potrivire cu aliasurile.
        return [
            __('exports.invoices.invoice_number'),
            __('exports.invoices.status'),
            __('exports.invoices.account'),
            __('exports.invoices.order'),
            __('exports.invoices.issue_date'),
            __('exports.invoices.due_date'),
            __('exports.invoices.currency'),
            __('exports.invoices.total'),
            __('exports.invoices.amount_paid'),
            __('exports.invoices.balance_due'),
        ];
    }

    /**
     * @param  Invoice  $row
     * @return list<string|int|float|null>
     */
    public function exportRow(Model $row): array
    {
        return [
            $row->invoice_number,
            $row->status,
            $row->order?->account?->name,
            $row->order?->order_number,
            $row->issue_date?->toDateString(),
            $row->due_date?->toDateString(),
            $row->currency,
            (float) $row->total,
            (float) $row->amount_paid,
            (float) $row->balance_due,
        ];
    }

    /** @param  Invoice  $row */
    public function archiveEntryName(Model $row): string
    {
        // `invoice_number` e secvențial per tenant (§12.1), deci unic în arhivă. Fallback pe
        // id doar pentru un rând scris fără număr — care n-ar trebui să existe, dar un
        // fișier numit `.pdf` ar fi mai rău decât unul numit cu ULID-ul.
        return ($row->invoice_number ?: $row->getKey()).'.pdf';
    }

    /** @param  Invoice  $row */
    public function archiveEntryPath(Model $row): ?string
    {
        return $row->pdf_status === Invoice::PDF_STATUS_READY ? $row->pdf_path : null;
    }

    /** @param  Invoice  $row */
    public function archiveEntryMissingReason(Model $row): string
    {
        $number = $row->invoice_number ?: $row->getKey();

        return match ($row->pdf_status) {
            Invoice::PDF_STATUS_PENDING => "{$number} — the PDF is still being generated. Try the export again in a moment.",
            Invoice::PDF_STATUS_FAILED => "{$number} — the PDF could not be generated. Open the invoice and retry it, then export again.",
            default => "{$number} — no PDF file is stored for this invoice.",
        };
    }
}
