<?php

namespace App\Support\Imports;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Descrierea unei resurse importabile (§14, MVP: accounts/contacts/products/variants) —
 * mirror-ul lui `App\Support\Bulk\BulkWritableResource` pentru latura de IMPORT. Sursă unică
 * pentru mapare, validare, detectarea de duplicate (FR-IMP-01) și scrierea finală (Commit).
 */
interface ImportableResource
{
    public function resourceType(): string;

    /** Etichetă umană, pentru UI (titluri, opțiuni de select). */
    public function label(): string;

    /** @return list<ImportField> */
    public function fields(): array;

    /**
     * Semnătura de duplicat a rândului MAPAT (după aplicarea `column_mapping`), sau `null`
     * dacă rândul nu oferă destule date pentru verificare (ex: contact fără email — FR-IMP-01
     * limitează explicit cheia de duplicat a contactelor la email).
     *
     * Un `array` cu `field`/`value`, nu un string fix: Accounts n-are un identificator extern
     * unic (spre deosebire de SKU/email), deci cheia efectivă variază per rând — `domain`
     * quando prezent, altfel `name` (motivat în raportul lotului).
     *
     * @param  array<string, mixed>  $mapped
     * @return array{field: string, value: string}|null
     */
    public function duplicateSignature(array $mapped): ?array;

    /**
     * Dintre valorile date pentru câmpul de duplicat, care există DEJA în baza tenantului
     * curent — o interogare BATCHED per chunk (`WhereIn`), nu una per rând.
     *
     * @param  list<string>  $values  deja normalizate de `duplicateSignature()`
     * @return list<string> subsetul deja existent
     */
    public function existingValues(string $field, array $values): array;

    /**
     * Pregătire per CHUNK, chemată o singură dată ÎNAINTE de `writeRow()` pe fiecare rând al
     * chunk-ului (P2, review general — N+1 la commit). Resursele care găsesc-sau-creează un
     * părinte per rând (`ContactImportResource` → cont, `VariantImportResource` → produs)
     * pre-rezolvă aici harta „nume normalizat → id" într-o SINGURĂ interogare `whereIn()`,
     * exact cum `existingValues()` face deja pentru duplicate — altfel `writeRow()` ar repeta
     * aceeași căutare de sute de ori într-un chunk de contacte/variante legate de puține
     * conturi/produse comune. No-op pentru resursele fără părinte (Accounts, Products).
     *
     * @param  list<array<string, mixed>>  $mappedRows  toate rândurile MAPATE ale chunk-ului curent
     */
    public function prepareChunk(array $mappedRows): void;

    /**
     * Scrie entitatea finală la Commit (Pasul 4). Apelantul garantează contextul de tenant
     * (`TenantContext::run()`) — implementarea nu deschide propriul context. Apelat DUPĂ
     * `prepareChunk()` pentru tot chunk-ul curent.
     *
     * @param  array<string, mixed>  $mapped
     */
    public function writeRow(array $mapped, User $user): Model;
}
