<?php

namespace App\Support\Bulk;

use App\Models\User;
use App\Support\Lists\ResourceList;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Descrierea unei resurse scriabile în masă (§13.2, §13.5): perechea `ResourceList` care
 * captează același filtru ca lista/exportul (task Pachetul C — „lista web, exportul și
 * operația în masă trebuie să vadă aceleași rânduri pentru același URL"), plus o interogare
 * de bază pe modelul Eloquent și îngustarea de proprietate pentru Agent (BR-BULK-02, §7.5).
 *
 * `scopeToOwnRecords()` se aplică ATÂT la `DispatchBulkOperationAction` (pentru numărul
 * corect afișat în dialogul de confirmare), CÂT ȘI din nou în `PlanBulkOperationJob`
 * (defense-in-depth): filtrul capturat la dispatch nu garantează singur izolarea de rol
 * dacă cineva manipulează cererea direct, ocolind UI-ul.
 */
interface BulkWritableResource
{
    public function resourceType(): string;

    /** @return class-string<ResourceList> */
    public function listClass(): string;

    /** @return class-string<Model> */
    public function modelClass(): string;

    /** Interogare proaspătă pe modelul resursei, fără filtre — cursorul de chunk pornește de aici. */
    public function newQuery(): Builder;

    /** BR-BULK-02 — Agent: doar subsetul „propriu" (owner și/sau creator, per resursă). */
    public function scopeToOwnRecords(Builder $query, User $user): void;
}
