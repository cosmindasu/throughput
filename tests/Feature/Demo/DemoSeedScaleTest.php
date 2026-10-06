<?php

namespace Tests\Feature\Demo;

use App\Models\Account;
use App\Models\ActivityLog;
use App\Models\Deal;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Stage;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Tests\TestCase;

/**
 * `demo:seed-volume --scale` — setul redus pentru suita E2E (plan §5.3, minutele de CI).
 *
 * E singurul test care rulează generatorul de seed cap-coadă. La scară completă durează
 * ~60 s; la scara minimă, câteva secunde. Prinde regresiile de generare (ordinea cheilor
 * străine, contextul de tenant, forma datelor) înainte să ajungă la `demo:reset` în producție.
 */
class DemoSeedScaleTest extends TestCase
{
    public function test_the_scaled_dataset_keeps_every_shape_the_e2e_flows_need(): void
    {
        $this->artisan('demo:seed-volume', ['--scale' => '0.001'])->assertSuccessful();

        $this->assertSame(['cascade', 'marlin', 'northgate'], Tenant::query()->orderBy('slug')->pluck('slug')->all());

        foreach (['owner', 'manager', 'agent', 'viewer'] as $role) {
            $this->assertTrue(
                User::query()->where('email', "demo.{$role}@throughput.dev")->exists(),
                "Lipsește contul demo {$role} (specs.md §4.2)."
            );
        }

        $marlin = Tenant::query()->where('slug', 'marlin')->firstOrFail();
        $agent = User::query()->where('email', 'demo.agent@throughput.dev')->firstOrFail();

        TenantContext::run($marlin, function () use ($agent): void {
            // Minimele scalării, nu 0,1% din volumul complet.
            $this->assertSame(40, Account::query()->count());
            $this->assertSame(60, Order::query()->count());
            $this->assertGreaterThan(0, Deal::query()->count());

            // Kanban-ul (§24.3, fluxul 3) are nevoie de etape terminale.
            $this->assertTrue(Stage::query()->where('is_won', true)->exists());
            $this->assertTrue(Stage::query()->where('is_lost', true)->exists());

            // „My accounts" al Agentului demo (US-CRM-02, fluxul RBAC din §24.3) nu e gol la
            // nicio scară — garanția din AccountsAndContactsSeeder, nu noroc statistic.
            $this->assertGreaterThanOrEqual(5, Account::query()->where('owner_user_id', $agent->getKey())->count());

            // ID-urile scrise în bloc au aceeași formă ca cele din Eloquent (litere mici) — vezi
            // DemoId. Cu majuscule, orice comparație după `Str::lower` pierdea rândul semănat.
            foreach ([Account::class, Deal::class, Order::class] as $model) {
                $this->assertSame(0, $model::query()->whereRaw("id ~ '[A-Z]'")->count(), "{$model}: id-uri cu majuscule.");
            }
        });
    }

    /**
     * Istoricul unei facturi, nu doar nașterea ei.
     *
     * Seed-ul scria UN rând per factură, deși le dădea stări reale: în producție, 34.776 de
     * rânduri `created` pe facturi și 18 `updated` în TOT jurnalul. Orice factură, plătită sau
     * anulată, avea în tab-ul „History" exact o linie — tocmai zona pe care un client o
     * deschide ca să vadă cum arată facturarea.
     *
     * Aici se fixează ce înseamnă „istoric corect", nu doar „istoric nevid": o tranziție nu
     * poate precede trimiterea, trimiterea nu poate precede emiterea, iar o factură rămasă în
     * draft n-are ce tranziții să aibă. Un seed care scrie rânduri în ordine greșită arată mai
     * rău decât unul care nu scrie deloc.
     */
    public function test_an_invoice_carries_its_lifecycle_not_just_its_birth(): void
    {
        $this->artisan('demo:seed-volume', ['--scale' => '0.001'])->assertSuccessful();

        $marlin = Tenant::query()->where('slug', 'marlin')->firstOrFail();

        TenantContext::run($marlin, function (): void {
            $facturi = Invoice::query()->get(['id', 'invoice_number', 'status', 'void_reason', 'voided_at']);
            $this->assertGreaterThan(0, $facturi->count());

            $jurnal = ActivityLog::query()
                ->where('auditable_type', Invoice::class)
                ->get(['auditable_id', 'action', 'new_values', 'created_at', 'user_id'])
                ->groupBy('auditable_id');

            $cuTranzitii = 0;

            foreach ($facturi as $factura) {
                $randuri = $jurnal->get($factura->getKey()) ?? collect();
                $moment = fn (string $status) => $randuri
                    ->first(fn (ActivityLog $r) => ($r->new_values['status'] ?? null) === $status)?->created_at;

                $emisa = $randuri->firstWhere('action', 'created')?->created_at;
                $this->assertNotNull($emisa, "Factura {$factura->invoice_number} n-are rând de creare.");

                $trimisa = $moment(Invoice::STATUS_SENT);

                if ($factura->status === Invoice::STATUS_DRAFT) {
                    $this->assertNull($trimisa, 'O factură rămasă în draft n-a fost trimisă nicăieri.');

                    continue;
                }

                $this->assertNotNull($trimisa, "Factura {$factura->invoice_number} ({$factura->status}) n-a trecut niciodată prin „sent”.");
                $this->assertGreaterThanOrEqual($emisa, $trimisa, 'Trimisă înainte de a fi emisă.');
                $cuTranzitii++;

                foreach ([Invoice::STATUS_PAID, Invoice::STATUS_OVERDUE, Invoice::STATUS_VOID] as $ulterior) {
                    $la = $moment($ulterior);

                    if ($la !== null) {
                        $this->assertGreaterThanOrEqual($trimisa, $la, "Trecută în „{$ulterior}” înainte de a fi trimisă.");
                    }
                }

                // Starea FINALĂ trebuie să aibă rândul ei: altfel ecranul arată „paid", iar
                // istoricul se oprește la „sent".
                if ($factura->status !== Invoice::STATUS_SENT) {
                    $this->assertNotNull($moment($factura->status), "Factura {$factura->invoice_number} e „{$factura->status}”, dar istoricul nu spune când a devenit.");
                }

                // `VoidInvoiceAction` cere motiv; `Invoices/Show.tsx` randează un panou cu el,
                // condiționat pe `voidReason` — fără motiv, elementul nu apare niciodată.
                if ($factura->status === Invoice::STATUS_VOID) {
                    $this->assertNotNull($factura->void_reason, 'O factură anulată fără motiv n-ar fi putut fi produsă de aplicație.');
                    $this->assertNotNull($factura->voided_at);
                }
            }

            $this->assertGreaterThan(0, $cuTranzitii, 'Niciun istoric cu mai mult de o linie — exact defectul reparat.');
        });
    }

    public function test_the_scale_must_be_a_fraction_of_the_full_dataset(): void
    {
        $this->artisan('demo:seed-volume', ['--scale' => '0'])->assertFailed();
        $this->artisan('demo:seed-volume', ['--scale' => '2'])->assertFailed();

        $this->assertSame(0, Tenant::query()->count());
    }
}
