<?php

namespace App\Jobs\System;

use App\Models\Invoice;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Queue\Queueable;

/**
 * BR-BILL-02 — job zilnic: `sent -> overdue` pentru orice factură cu `due_date` trecut
 * și `balance_due > 0`. Job de SISTEM (`.ai/rules/tenancy.md`, ADR-014 pct. 4, ca
 * `DispatchScheduledReportsJob`/`PruneExpiredExportsJob`): fără tenant propriu, iterează
 * tenanții explicit, cu un context per tenant, pe conexiunea aplicației — NICIODATĂ pe
 * cea cu BYPASSRLS (rezervată exclusiv `artisan migrate`).
 *
 * CORECTAT — tranziția nu ajungea în `activity_log`, verificat direct: docblock-ul
 * anterior de aici afirma că, odată ce lotul E adaugă `Invoice` la
 * `ActivityLogServiceProvider::observedModels()`, tranziția „va apărea automat în jurnal,
 * fără nicio modificare aici". Fals — `Invoice::query()->...->update([...])` e un UPDATE
 * ÎN MASĂ prin query builder, care NU declanșează evenimentele Eloquent, deci
 * `App\Observers\ActivityLogObserver::updated()` nu era chemat niciodată, indiferent câte
 * modele erau înregistrate ca observate. Fixul de mai jos salvează fiecare factură ca
 * MODEL (`$invoice->update()`), singurul fel prin care evenimentul `updated` pornește
 * lanțul unic de audit din ADR-007 (observer → `ModelWasRecorded` → listener-ul pe coadă
 * `WriteActivityLogEntry`) — NU un scris direct în `activity_log`, care ar fi a doua cale
 * de scriere, exact ce ADR-007 exclude explicit. `Auth::id()` (citit de observer) e
 * `null` în firul acestui job de consolă/coadă — fără sesiune HTTP autentificată — deci
 * `user_id = null` cerut de BR-BILL-02 vine gratuit, din context, nu dintr-un caz special.
 *
 * MEMORIE — de ce `chunkById()`, nu `get()`. Trecerea de la un UPDATE în masă la iterare
 * per-model a adus, pe lângă jurnal, un cost pe care varianta veche nu-l avea: `get()` ar
 * materializa TOATE facturile eligibile ale unui tenant deodată. Nu e teoretic —
 * `database/seeders/Demo/BillingSeeder.php:77` seamănă 18% dintre facturi ca `sent`, adică
 * ~4.900 la tenantul vitrină, o bună parte cu `due_date` trecut din cele 24 de luni de
 * istoric; prima rulare de după un seed sau un `demo:reset` le-ar prinde pe toate odată, pe
 * un container cu buget de 250-400 MB și cu ucideri OOM în istoricul VPS-ului
 * (`.ai/rules/project.md`). În regim zilnic de croazieră delta e mică — dar un job care
 * cade exact după reîncărcarea demo-ului e un job care cade când e privit.
 *
 * `chunkById()`, NU `chunk()`: cursorul avansează pe `id > ultimul_id`, deci rândurile deja
 * trecute pe `overdue` rămân în urma lui și nu contează că ies din `WHERE status = 'sent'`.
 * `chunk()` cu OFFSET ar sări rânduri exact din acest motiv. Fără `orderBy` alături —
 * capcana documentată deja în `ImportErrorReportBuilder:49`, unde `chunkById` își impune
 * propria ordonare pe cheie.
 *
 * CONCURENȚĂ — de ce garanția veche (un singur `UPDATE ... WHERE` atomic, fără cursă de
 * citește-apoi-scrie) NU se pierde la trecerea pe iterare per-model: `SELECT candidații,
 * apoi UPDATE fiecare` ar fi, luat separat, exact cursa clasică. Ce o închide e
 * `->lock('for no key update')` pe interogarea de selecție, executată ÎN interiorul
 * tranzacției pe care `TenantContext::run()` o deschide deja pentru acest tenant (nicio
 * tranzacție suplimentară aici — ar fi imbricată degeaba). `FOR NO KEY UPDATE`, nu
 * `FOR UPDATE` (`.ai/rules/tenancy.md`, „Blocarea unui rând părinte"): factura e rândul
 * chiar modificat aici, dar are un copil cu FK (`payments.invoice_id`) — `FOR UPDATE` ar
 * bloca și o înregistrare de plată complet nelegată de cursa asta, cât ține tranzacția
 * jobului; `FOR NO KEY UPDATE` serializează exact ce trebuie (două rulări ale ACESTUI job
 * pe același rând), fără să oprească acea inserare în tabela copil.
 *
 * Verificat, nu presupus: `MarkOverdueInvoicesJobConcurrencyTest` reia tehnica din
 * `ActivateCarrierConcurrencyTest` (două conexiuni Postgres reale, a doua cu
 * `lock_timeout` scurt) — o a doua „rulare" care încearcă ACEEAȘI `SELECT ... FOR NO KEY
 * UPDATE ... WHERE status = 'sent' ...` pe rândul deja blocat de prima dă timeout, nu
 * trece instant (proba că se serializează, nu doar „de obicei"). După ce prima tranzacție
 * COMITE (starea a trecut la `overdue`), Postgres re-evaluează clauza WHERE a celei
 * de-a doua pe versiunea NOUĂ a rândului — comportament documentat pentru orice
 * `SELECT ... FOR {UPDATE|NO KEY UPDATE|SHARE|KEY SHARE}` sub READ COMMITTED, nu doar
 * pentru `UPDATE`/`DELETE` simplu (PostgreSQL, „13.3.1. Read Committed Isolation Level")
 * — rândul nu mai satisface `status = 'sent'`, deci a doua rulare nu-l mai vede, nu-l
 * atinge a doua oară și nu scrie un al doilea rând de jurnal. Testat și pe reluare
 * SECVENȚIALĂ (redeploy fără suprapunere reală): `test_it_is_idempotent_on_a_second_run`
 * verifică acum și că a doua `->handle()` nu adaugă un al doilea rând în `activity_log`.
 *
 * Precizare, de când iterarea merge pe tranșe: blocarea se ia acum per TRANȘĂ, nu pe toată
 * mulțimea eligibilă deodată, deci două rulări concurente se pot întrepătrunde între tranșe.
 * Garanția care contează rămâne însă neatinsă, fiindcă e per RÂND: a doua rulare se blochează
 * pe primul rând deja blocat, iar după commit-ul primei îl re-evaluează, nu-l mai găsește
 * `sent` și trece mai departe. Nu există „serializare globală a jobului" — și nici nu e
 * nevoie de una: nimic nu depinde de ordinea sau de gruparea rândurilor.
 */
class MarkOverdueInvoicesJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    /**
     * `limits.overdue_chunk_size` (implicit 500, ca `import_chunk_size`/`bulk_chunk_size`).
     * Stă în configurare, nu ca o constantă locală, dintr-un singur motiv: ca un test să-l
     * poată coborî la 2 și să dovedească ieftin că iterarea pe tranșe nu sare rânduri —
     * altfel aceeași dovadă ar cere 501 comenzi reale, iar fără ea regresia clasică
     * (`chunkById` schimbat în `chunk`, sau un `orderBy` adăugat alături) ar trece verde.
     */
    private function chunkSize(): int
    {
        return (int) config('throughput.limits.overdue_chunk_size', 500);
    }

    public function handle(): void
    {
        Tenant::query()->eachById(function (Tenant $tenant): void {
            TenantContext::run($tenant, function (): void {
                Invoice::query()
                    ->where('status', Invoice::STATUS_SENT)
                    ->whereNotNull('due_date')
                    ->whereDate('due_date', '<', now()->toDateString())
                    ->where('balance_due', '>', 0)
                    ->lock('for no key update')
                    ->chunkById($this->chunkSize(), function (Collection $invoices): void {
                        $invoices->each(fn (Invoice $invoice) => $invoice->update(['status' => Invoice::STATUS_OVERDUE]));
                    });
            });
        });
    }
}
