<?php

namespace App\Support\Imports\Resources;

use App\Models\Account;
use App\Models\User;
use App\Support\Imports\ImportableResource;
use App\Support\Imports\ImportField;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Conturi (§14.1, §7.4 — Owner/Manager CRUD). Cheia de duplicat NU e dictată de FR-IMP-01
 * (care fixează explicit doar email/contacte și SKU/variante) — decizie proprie, motivată în
 * raportul lotului: `domain` când e prezent (identificator extern mai stabil decât numele —
 * două firme diferite pot avea nume asemănătoare, rareori același domeniu), altfel `name`
 * normalizat (case-insensitive, spații tăiate). Niciodată ambele simultan pe un rând: un
 * cont cu domeniu se dedupe pe domeniu, nu pe nume, ca „Acme Inc" și „ACME INC." cu domenii
 * diferite să nu se respingă reciproc.
 */
final class AccountImportResource implements ImportableResource
{
    public function resourceType(): string
    {
        return 'accounts';
    }

    public function label(): string
    {
        return 'Accounts';
    }

    /**
     * BR-I18N-01 (specs.md §15.8, ADR-022) — aliasurile FR de mai jos sunt ADĂUGATE la
     * lista engleză deja existentă, niciodată în locul ei: cheia (`name`, `domain`, ...)
     * rămâne stabilă indiferent de `locale`, `ImportRowMapper` mapează tot pe ea. Fără
     * aliasurile astea, un CSV exportat dintr-un mediu francofon (antete de forma „Nom de
     * l'entreprise") ar ateriza pe „confidence: none" la reimport — mecanismul de mapare
     * automată (`ColumnMappingSuggester`) nu are cum să știe că „Nom de l'entreprise" și
     * „Company name" sunt același câmp fără un alias explicit.
     */
    public function fields(): array
    {
        return [
            new ImportField('name', 'Company name', true, ['required', 'string', 'max:255'], [
                'name', 'company name', 'account name', 'company', 'business name', 'organization',
                // FR — exemplul chiar citat de BR-I18N-01 în specs.md §15.8.
                'nom', "nom de l'entreprise", 'nom de la société', 'société', 'raison sociale', 'entreprise',
            ]),
            new ImportField('domain', 'Domain', false, ['nullable', 'string', 'max:255'], [
                'domain', 'website', 'company domain', 'url', 'web site',
                'domaine', 'site web', "domaine de l'entreprise", 'site internet',
            ]),
            new ImportField('industry', 'Industry', false, ['nullable', 'string', 'max:255'], [
                'industry', 'sector', 'vertical',
                'secteur', "secteur d'activité", 'industrie',
            ]),
            new ImportField('phone', 'Phone', false, ['nullable', 'string', 'max:30'], [
                'phone', 'phone number', 'telephone', 'tel',
                'téléphone', 'numéro de téléphone', 'tél',
            ]),
            new ImportField('source', 'Source', false, ['nullable', 'string', 'max:255'], [
                'source', 'lead source',
                'source du prospect', 'origine',
            ]),
        ];
    }

    public function duplicateSignature(array $mapped): ?array
    {
        $domain = self::normalizeDomain((string) ($mapped['domain'] ?? ''));

        if ($domain !== '') {
            return ['field' => 'domain', 'value' => $domain];
        }

        $name = trim((string) ($mapped['name'] ?? ''));

        if ($name !== '') {
            return ['field' => 'name', 'value' => Str::lower($name)];
        }

        return null;
    }

    /**
     * `name` — `whereIn('name_lower', ...)`, coloana GENERATĂ/STOCATĂ (migrația
     * `2026_09_19_190000_...`), nu `DB::raw('lower(name)')`: sub RLS, Postgres refuză să
     * împingă o funcție ne-leakproof (`lower()` chiar e ne-leakproof, verificat pe
     * `pg_proc`) sub bariera de securitate a politicii, deci un index FUNCȚIONAL pe
     * `lower(name)` era ignorat de planificator pentru rolul aplicației — măsurat cu
     * `EXPLAIN`, `Seq Scan` neschimbat față de „fără index" (raportul lotului). O coloană
     * generată STOCATĂ elimină funcția din interogare: `name_lower = ?` e o egalitate
     * simplă, indexabilă normal.
     *
     * `domain` rămâne pe `DB::raw('lower(domain)')` — fără coloană generată (în afara
     * cererii acestei runde, notat ca gol cunoscut în raport): funcțional corect, doar
     * neindexat, exact ca înainte.
     */
    public function existingValues(string $field, array $values): array
    {
        if ($values === []) {
            return [];
        }

        if ($field === 'name') {
            return Account::query()->whereIn('name_lower', $values)->pluck('name_lower')->all();
        }

        return Account::query()
            ->whereIn(DB::raw("lower({$field})"), $values)
            ->pluck($field)
            ->map(fn (?string $value) => Str::lower(self::normalizeIfDomain($field, $value ?? '')))
            ->all();
    }

    /** Fără părinte de rezolvat per rând (spre deosebire de Contacts/Variants) — no-op. */
    public function prepareChunk(array $mappedRows): void
    {
        // Intenționat gol.
    }

    public function writeRow(array $mapped, User $user): Model
    {
        $account = new Account([
            'name' => trim((string) $mapped['name']),
            'domain' => filled($mapped['domain'] ?? null) ? trim((string) $mapped['domain']) : null,
            'industry' => filled($mapped['industry'] ?? null) ? trim((string) $mapped['industry']) : null,
            'phone' => filled($mapped['phone'] ?? null) ? trim((string) $mapped['phone']) : null,
            'source' => filled($mapped['source'] ?? null) ? trim((string) $mapped['source']) : null,
            'status' => Account::STATUS_PROSPECT,
            'owner_user_id' => $user->getKey(),
        ]);
        $account->created_by = $user->getKey();
        $account->save();

        return $account;
    }

    private static function normalizeIfDomain(string $field, string $value): string
    {
        return $field === 'domain' ? self::normalizeDomain($value) : $value;
    }

    /** `https://www.Acme.com/` → `acme.com` — ca două scrieri diferite ale aceluiași domeniu să se dedupe. */
    private static function normalizeDomain(string $domain): string
    {
        $domain = trim($domain);

        if ($domain === '') {
            return '';
        }

        $domain = Str::lower($domain);
        $domain = (string) preg_replace('#^[a-z]+://#', '', $domain);
        $domain = (string) preg_replace('#^www\.#', '', $domain);

        return rtrim(explode('/', $domain)[0], '/');
    }
}
