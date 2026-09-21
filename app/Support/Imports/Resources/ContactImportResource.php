<?php

namespace App\Support\Imports\Resources;

use App\Models\Account;
use App\Models\Contact;
use App\Models\User;
use App\Support\Imports\ImportableResource;
use App\Support\Imports\ImportField;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Contacte (§14.1). Cheia de duplicat e FIXATĂ de FR-IMP-01: email. Un contact fără email nu
 * are cum fi verificat pentru duplicat — `duplicateSignature()` întoarce `null`, rândul se
 * importă necondiționat (nu există „silent overwrite" posibil fără o cheie).
 *
 * `account_name` NU e o coloană Contact — e folosită doar pentru a găsi/crea contul-părinte
 * (find-or-create pe nume, case-insensitive), simetric cu `VariantImportResource` și
 * produsul-părinte. Fără ea, contactul se creează fără cont (`account_id` e nullabil în
 * schemă).
 */
final class ContactImportResource implements ImportableResource
{
    /**
     * Hartă „nume de cont normalizat → id", populată de `prepareChunk()` și completată
     * write-through în `findOrCreateAccountId()` (P2, review general — N+1 la commit: fără
     * ea, `writeRow()` rula un `whereRaw('lower(name) = ?')` PER RÂND, aceeași interogare de
     * sute de ori într-un chunk de contacte legate de puține conturi comune). Instanța
     * resursei e proaspătă la fiecare chunk (`ImportableResources::resolve()` în
     * `CommitImportJob`, o dată per invocare de job), deci harta nu supraviețuiește între
     * chunk-uri — corect, fiindcă un cont creat de UN chunk trebuie să fie deja vizibil în
     * bază (nu doar în cache) pentru chunk-ul URMĂTOR, care oricum repopulează harta din bază.
     *
     * @var array<string, string>
     */
    private array $accountIdByNormalizedName = [];

    public function resourceType(): string
    {
        return 'contacts';
    }

    public function label(): string
    {
        return __('imports.resources.contacts');
    }

    /** BR-I18N-01 — vezi docblock-ul identic din `AccountImportResource::fields()`. */
    public function fields(): array
    {
        return [
            new ImportField('first_name', 'imports.fields.contacts.first_name', true, ['required', 'string', 'max:255'], [
                'first name', 'firstname', 'given name',
                'prénom',
            ]),
            // `nom` (fără „de famille"/„famille") e alias sigur aici DOAR fiindcă niciun alt
            // câmp din ACEASTĂ resursă nu-l revendică — spre deosebire de conturi, unde
            // „name" e deja câmpul de firmă (vezi docblock-ul `AccountImportResource`).
            new ImportField('last_name', 'imports.fields.contacts.last_name', true, ['required', 'string', 'max:255'], [
                'last name', 'lastname', 'surname', 'family name',
                'nom', 'nom de famille',
            ]),
            new ImportField('email', 'imports.fields.contacts.email', false, ['nullable', 'email', 'max:255'], [
                'email', 'email address', 'e-mail',
                'adresse e-mail', 'adresse email', 'courriel',
            ]),
            new ImportField('phone', 'imports.fields.contacts.phone', false, ['nullable', 'string', 'max:30'], [
                'phone', 'phone number', 'telephone', 'tel',
                'téléphone', 'numéro de téléphone', 'tél',
            ]),
            new ImportField('title', 'imports.fields.contacts.title', false, ['nullable', 'string', 'max:255'], [
                'title', 'job title', 'position', 'role',
                'poste', 'fonction', 'titre du poste',
            ]),
            new ImportField('account_name', 'imports.fields.contacts.account_name', false, ['nullable', 'string', 'max:255'], [
                'company', 'company name', 'account', 'account name', 'organization',
                'société', 'entreprise', "nom de l'entreprise", 'compte',
            ]),
        ];
    }

    public function duplicateSignature(array $mapped): ?array
    {
        $email = trim((string) ($mapped['email'] ?? ''));

        if ($email === '') {
            return null;
        }

        return ['field' => 'email', 'value' => Str::lower($email)];
    }

    public function existingValues(string $field, array $values): array
    {
        if ($values === []) {
            return [];
        }

        return Contact::query()
            ->whereIn(DB::raw('lower(email)'), $values)
            ->pluck('email')
            ->map(fn (?string $value) => Str::lower($value ?? ''))
            ->filter()
            ->all();
    }

    /**
     * O SINGURĂ interogare `whereIn('name_lower', ...)` pentru TOATE numele de cont distincte
     * din chunk — nu una per rând. `name_lower` (coloană GENERATĂ/STOCATĂ, migrația
     * `2026_09_19_190000_...`), la fel ca `AccountImportResource`/`ProductImportResource`.
     *
     * @param  list<array<string, mixed>>  $mappedRows
     */
    public function prepareChunk(array $mappedRows): void
    {
        $this->accountIdByNormalizedName = [];

        $names = collect($mappedRows)
            ->map(fn (array $row) => trim((string) ($row['account_name'] ?? '')))
            ->filter(fn (string $name) => $name !== '')
            ->map(fn (string $name) => Str::lower($name))
            ->unique()
            ->values()
            ->all();

        if ($names === []) {
            return;
        }

        Account::query()
            ->whereIn('name_lower', $names)
            ->get(['id', 'name_lower'])
            ->each(function (Account $account): void {
                $this->accountIdByNormalizedName[$account->name_lower] = $account->getKey();
            });
    }

    public function writeRow(array $mapped, User $user): Model
    {
        $accountId = $this->findOrCreateAccountId((string) ($mapped['account_name'] ?? ''), $user);

        $contact = new Contact([
            'account_id' => $accountId,
            'first_name' => trim((string) $mapped['first_name']),
            'last_name' => trim((string) $mapped['last_name']),
            'email' => filled($mapped['email'] ?? null) ? Str::lower(trim((string) $mapped['email'])) : null,
            'phone' => filled($mapped['phone'] ?? null) ? trim((string) $mapped['phone']) : null,
            'title' => filled($mapped['title'] ?? null) ? trim((string) $mapped['title']) : null,
        ]);
        $contact->created_by = $user->getKey();
        $contact->save();

        return $contact;
    }

    private function findOrCreateAccountId(string $accountName, User $user): ?string
    {
        $accountName = trim($accountName);

        if ($accountName === '') {
            return null;
        }

        $normalized = Str::lower($accountName);

        if (isset($this->accountIdByNormalizedName[$normalized])) {
            return $this->accountIdByNormalizedName[$normalized];
        }

        $account = new Account([
            'name' => $accountName,
            'status' => Account::STATUS_PROSPECT,
            'owner_user_id' => $user->getKey(),
        ]);
        $account->created_by = $user->getKey();
        $account->save();

        // Write-through: un cont creat de ACEST rând trebuie găsit de rândurile URMĂTOARE ale
        // ACELUIAȘI chunk, fără o interogare nouă (§ docblock-ul proprietății de mai sus).
        $this->accountIdByNormalizedName[$normalized] = $account->getKey();

        return $account->getKey();
    }
}
