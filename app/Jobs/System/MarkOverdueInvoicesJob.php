<?php

namespace App\Jobs\System;

use App\Models\Invoice;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
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
 * MEMORIE — de ce tranșe (`LIMIT` + cursor pe `id`), nu `get()` pe tot tenantul deodată.
 * Trecerea de la un UPDATE în masă la iterare per-model a adus, pe lângă jurnal, un cost pe
 * care varianta veche nu-l avea: `get()` ar materializa TOATE facturile eligibile ale unui
 * tenant deodată. Nu e teoretic — `database/seeders/Demo/BillingSeeder.php:77` seamănă 18%
 * dintre facturi ca `sent`, adică ~4.900 la tenantul vitrină, o bună parte cu `due_date`
 * trecut din cele 24 de luni de istoric; prima rulare de după un seed sau un `demo:reset`
 * le-ar prinde pe toate odată, pe un container cu buget de 250-400 MB și cu ucideri OOM în
 * istoricul VPS-ului (`.ai/rules/project.md`). În regim zilnic de croazieră delta e mică —
 * dar un job care cade exact după reîncărcarea demo-ului e un job care cade când e privit.
 *
 * CORECTAT A DOUA OARĂ (audit 2026-09-23, DOM-02) — o singură tranzacție ținea TOT
 * tenantul, nu doar tranșa curentă. Varianta anterioară de aici înfășură bucla de tranșe
 * (fie `chunkById()`, fie un `do…while` care apela `TenantContext::run()` o singură dată,
 * pe la capătul de sus) într-UN SINGUR apel al lui `TenantContext::run()`: fiindcă
 * `TenantContext::run()` deschide o SINGURĂ tranzacție Postgres pentru tot ce rulează în
 * closure-ul primit, blocările `FOR NO KEY UPDATE` acumulate de fiecare tranșă rămâneau
 * ținute până la commit-ul FINAL — adică până ce tenantul ÎNTREG era procesat, nu până la
 * finalul tranșei curente. Măsurat pe cod, nu presupus: `RegisterPaymentAction` blochează
 * ACEEAȘI factură cu `lockForUpdate()`, care intră în conflict cu `FOR NO KEY UPDATE` — un
 * client care încearcă să plătească exact o factură din backlog-ul pe care jobul îl
 * procesează ar aștepta nu durata unui `UPDATE`, ci durata ÎNTREGULUI backlog restant al
 * tenantului (până la `timeout` de 300s al jobului).
 *
 * Fixul e `processTenant()`: `do … while`, iar FIECARE iterație deschide propriul
 * `TenantContext::run()` — deci propria tranzacție Postgres. O tranșă intră, ia
 * următoarele `chunkSize()` id-uri eligibile de DUPĂ ultimul procesat (cu
 * `FOR NO KEY UPDATE`), le marchează, și `run()` face commit la ieșire — eliberând
 * blocările înainte ca tranșa următoare să înceapă. Cursorul (`$lastProcessedId`) trece de
 * la o iterație la alta ÎN PHP, nu în SQL — nimic nu-l ține peste commit, exact ca la
 * `chunkById()`, dar cu o tranzacție NOUĂ la fiecare pas, nu una singură pentru tot
 * tenantul. Bucla se oprește când o tranșă întoarce MAI PUȚINE rânduri decât `chunkSize()`
 * — semn că n-a mai rămas nimic eligibil (ultima tranșă parțială sau una goală).
 *
 * De ce cursor pe `id`, nu doar „ia din nou primele N eligibile": rândurile deja trecute pe
 * `overdue` ies singure din `WHERE status = 'sent'`, deci o interogare fără cursor n-ar sări
 * rânduri — dar ar reface, la fiecare tranșă, un scan de la ÎNCEPUTUL tabelei peste cele deja
 * procesate, din ce în ce mai costisitor pe măsură ce backlog-ul se subțiază. `id >
 * $lastProcessedId` (cu `orderBy('id')` alături — fără el, `LIMIT` n-ar avea o ordine
 * stabilă de tăiat) ține fiecare interogare la fel de ieftină, tranșă după tranșă, exact
 * motivul pentru care `chunkById()` există în Eloquent.
 *
 * CONCURENȚĂ — de ce garanția veche (un singur `UPDATE ... WHERE` atomic, fără cursă de
 * citește-apoi-scrie) NU se pierde la trecerea pe iterare per-model, per-tranșă: `SELECT
 * candidații, apoi UPDATE fiecare` ar fi, luat separat, exact cursa clasică. Ce o închide e
 * `->lock('for no key update')` pe interogarea de selecție, executată ÎN interiorul
 * tranzacției pe care `TenantContext::run()` o deschide pentru ACEA tranșă (nicio tranzacție
 * suplimentară aici — ar fi imbricată degeaba). `FOR NO KEY UPDATE`, nu `FOR UPDATE`
 * (`.ai/rules/tenancy.md`, „Blocarea unui rând părinte"): factura e rândul chiar modificat
 * aici, dar are un copil cu FK (`payments.invoice_id`) — `FOR UPDATE` ar bloca și o
 * înregistrare de plată complet nelegată de cursa asta, cât ține tranzacția tranșei;
 * `FOR NO KEY UPDATE` serializează exact ce trebuie (două rulări ale ACESTUI job pe același
 * rând), fără să oprească acea inserare în tabela copil.
 *
 * Verificat, nu presupus: `MarkOverdueInvoicesJobConcurrencyTest` reia tehnica din
 * `ActivateCarrierConcurrencyTest` (două conexiuni Postgres reale, a doua cu `lock_timeout`
 * scurt) — o a doua „rulare" care încearcă ACEEAȘI `SELECT ... FOR NO KEY UPDATE ... WHERE
 * status = 'sent' ...` pe rândul deja blocat de prima dă timeout, nu trece instant (proba că
 * se serializează, nu doar „de obicei"). După ce prima tranzacție COMITE (starea a trecut la
 * `overdue`), Postgres re-evaluează clauza WHERE a celei de-a doua pe versiunea NOUĂ a
 * rândului — comportament documentat pentru orice `SELECT ... FOR {UPDATE|NO KEY UPDATE|
 * SHARE|KEY SHARE}` sub READ COMMITTED, nu doar pentru `UPDATE`/`DELETE` simplu (PostgreSQL,
 * „13.3.1. Read Committed Isolation Level") — rândul nu mai satisface `status = 'sent'`, deci
 * a doua rulare nu-l mai vede, nu-l atinge a doua oară și nu scrie un al doilea rând de
 * jurnal. Testat și pe reluare SECVENȚIALĂ (redeploy fără suprapunere reală):
 * `test_it_is_idempotent_on_a_second_run` verifică acum și că a doua `->handle()` nu adaugă
 * un al doilea rând în `activity_log`.
 *
 * Blocarea se ia acum per TRANȘĂ, ȘI se ELIBEREAZĂ per tranșă — nu doar interogarea diferă,
 * tranzacția însăși e alta la fiecare pas: două rulări concurente ale jobului se pot
 * întrepătrunde între tranșe, iar a doua poate începe să proceseze tenantul respectiv imediat
 * după commit-ul primei tranșe a primei rulări, nu la finalul ei. Garanția care contează
 * rămâne totuși neatinsă, fiindcă e per RÂND: a doua rulare se blochează pe primul rând deja
 * blocat de oricare tranșă activă, iar după commit-ul acesteia îl re-evaluează, nu-l mai
 * găsește `sent` și trece mai departe. Nu există „serializare globală a jobului" — și nici
 * n-a existat vreodată, nici la nivel de tenant, nici la nivel de tranșă: nimic nu depinde de
 * ordinea sau de gruparea rândurilor.
 */
class MarkOverdueInvoicesJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    /**
     * `limits.overdue_chunk_size` (implicit 500, ca `import_chunk_size`/`bulk_chunk_size`).
     * Stă în configurare, nu ca o constantă locală, dintr-un singur motiv: ca un test să-l
     * poată coborî la 2 și să dovedească ieftin — fie că iterarea pe tranșe nu sare rânduri,
     * fie că fiecare tranșă își deschide propria tranzacție (`TenantContext::run()` chemat
     * o dată per tranșă, nu o singură dată pentru tot tenantul) — altfel oricare din cele
     * două dovezi ar cere sute de facturi reale, iar regresia clasică (`chunkById` schimbat
     * în `chunk`, un `orderBy` scos, sau bucla de tranșe reîmpachetată într-un singur `run()`)
     * ar trece verde.
     */
    private function chunkSize(): int
    {
        return (int) config('throughput.limits.overdue_chunk_size', 500);
    }

    public function handle(): void
    {
        Tenant::query()->eachById(function (Tenant $tenant): void {
            $this->processTenant($tenant);
        });
    }

    /**
     * Vezi docblock-ul clasei, secțiunea „CORECTAT A DOUA OARĂ". `do … while` în loc de
     * `chunkById()`: `chunkById()` ar rula tot corpul ei — deci toate tranșele — ÎN
     * INTERIORUL unui singur closure, adică al unei singure tranzacții dacă acel closure ar
     * fi el însuși înfășurat într-un singur `TenantContext::run()`. Aici e nevoie de exact
     * opusul: o tranzacție NOUĂ (deci un `TenantContext::run()` NOU) la fiecare tranșă, ca
     * blocările `FOR NO KEY UPDATE` să se elibereze la commit-ul tranșei, nu la finalul
     * tenantului. `$lastProcessedId` trăiește AICI, în PHP, între apeluri succesive ale lui
     * `run()` — Postgres nu ține nimic peste commit, deci cursorul trebuie purtat explicit.
     */
    private function processTenant(Tenant $tenant): void
    {
        $lastProcessedId = null;

        do {
            $processed = TenantContext::run($tenant, function () use (&$lastProcessedId): int {
                $invoices = Invoice::query()
                    ->where('status', Invoice::STATUS_SENT)
                    ->whereNotNull('due_date')
                    ->whereDate('due_date', '<', now()->toDateString())
                    ->where('balance_due', '>', 0)
                    ->when(
                        $lastProcessedId !== null,
                        fn (Builder $query): Builder => $query->where('id', '>', $lastProcessedId),
                    )
                    ->orderBy('id')
                    ->lock('for no key update')
                    ->limit($this->chunkSize())
                    ->get();

                $invoices->each(fn (Invoice $invoice) => $invoice->update(['status' => Invoice::STATUS_OVERDUE]));

                if ($invoices->isNotEmpty()) {
                    $lastProcessedId = $invoices->last()->getKey();
                }

                return $invoices->count();
            });
        } while ($processed === $this->chunkSize());
    }
}
