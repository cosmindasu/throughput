<?php

namespace App\Support\Lists;

use App\Models\Contact;
use App\Models\User;
use App\Support\Exports\ExportableList;
use App\Support\ListQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Lista de contacte — FR-CRM-02, US-CRM-01, plan §8.
 *
 * Spre deosebire de `AccountList`, nu există un filtru implicit „My contacts": §7.5
 * restrânge doar EDITAREA/ȘTERGEREA la Agent (`ContactPolicy`), nu și citirea — un
 * Agent vede tot tenantul de contacte, exact ca Manager/Owner/Viewer.
 */
final class ContactList extends ResourceList implements ExportableList
{
    protected function filterKeys(): array
    {
        return ['q', 'account'];
    }

    protected function sortableColumns(): array
    {
        return ['last_name', 'created_at'];
    }

    protected function defaultSort(): string
    {
        return 'last_name';
    }

    protected function accepts(string $key, string $value): bool
    {
        return match ($key) {
            // Un ULID malformat (link vechi, tastat greșit) se ignoră — nu 422 pe un link
            // partajat (plan §1.2 regula 6).
            'account' => Str::isUlid($value),
            default => true,
        };
    }

    protected function baseQuery(): Builder
    {
        // `owner_user_id` e în select deși `ContactResource` nu-l expune: e citit de
        // `ContactPolicy::isWithinOwnRecords()` pentru `can.edit` pe fiecare rând — un
        // select mai îngust l-ar întoarce `null` (nu o eroare), și rândul unui Agent
        // responsabil de cont ar pierde tăcut acțiunea de editare (§7.5).
        return Contact::query()->with('account:id,name,owner_user_id');
    }

    protected function applyFilters(Builder $query, ListQuery $list, User $user): void
    {
        if (($search = $list->filter('q')) !== null) {
            $escaped = '%'.addcslashes($search, '%_\\').'%';

            $query->where(function (Builder $scoped) use ($escaped): void {
                $scoped
                    ->where('first_name', 'ilike', $escaped)
                    ->orWhere('last_name', 'ilike', $escaped)
                    ->orWhere('email', 'ilike', $escaped);
            });
        }

        if (($accountId = $list->filter('account')) !== null) {
            $query->where('account_id', $accountId);
        }
    }

    /** @return list<string> */
    public function exportHeaders(): array
    {
        // FR-I18N-04 — vezi nota din `AccountList::exportHeaders()`.
        return [
            __('exports.contacts.first_name'),
            __('exports.contacts.last_name'),
            __('exports.contacts.email'),
            __('exports.contacts.phone'),
            __('exports.contacts.title'),
            __('exports.contacts.account'),
            __('exports.contacts.primary_contact'),
            __('exports.contacts.marketing_opt_out'),
            __('exports.contacts.created_at'),
        ];
    }

    /**
     * @param  Contact  $row
     * @return list<string|int|float|null>
     */
    public function exportRow(Model $row): array
    {
        return [
            $row->first_name,
            $row->last_name,
            $row->email,
            $row->phone,
            $row->title,
            $row->account?->name,
            $row->is_primary ? 'yes' : 'no',
            // BR-CRM-02: opțiunea suprimă doar marketingul, deci exportul o arată explicit —
            // e exact coloana de care are nevoie cine lucrează lista offline.
            $row->opt_out ? 'yes' : 'no',
            $row->created_at?->toIso8601String(),
        ];
    }
}
