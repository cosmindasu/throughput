<?php

namespace Tests\Feature\Exports;

use App\Models\BulkOperation;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Support\Permissions;
use Database\Factories\AccountFactory;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * P1-001 — injecție de formule CSV (OWASP CSV/Formula Injection). `name`-ul unui cont e
 * introdus de vizitatorii demo-ului public, deci trece direct în `fputcsv` fără curățare —
 * o valoare care începe cu `=`, `+`, `-`, `@`, tab sau CR se deschide ca formulă la
 * import în Excel/Sheets. Remediată o singură dată, în `CsvExporter` (comună celor două
 * căi), verificată aici pe AMBELE: exportul sincron și cel din coada `bulk`.
 */
class CsvFormulaInjectionTest extends TestCase
{
    /** @var array<string, string> */
    private const DANGEROUS_PREFIXES = [
        'equals' => '=',
        'plus' => '+',
        'minus' => '-',
        'at' => '@',
        'tab' => "\t",
        'carriage_return' => "\r",
    ];

    private Tenant $marlin;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->marlin = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $this->owner = $this->makeMember($this->marlin, 'demo.owner@throughput.dev', Permissions::OWNER);

        $this->clearDatabaseTenantContext();
    }

    public function test_a_synchronous_export_neutralizes_every_dangerous_prefix(): void
    {
        $this->seedDangerousAccounts();

        $response = $this->actingAs($this->owner)->get('/marlin/accounts/export');
        $response->assertOk();

        $this->assertEscapedForEveryPrefix($this->parseCsv($response->getContent()));
    }

    public function test_a_queued_export_neutralizes_every_dangerous_prefix(): void
    {
        Storage::fake('local');
        // Sub prag ar răspunde sincron — forțăm calea în coadă, ca P1-001 să fie acoperit
        // și pe drumul prin `ExportListJob`, nu doar pe cel sincron.
        config(['throughput.limits.export_sync_max_rows' => 0]);

        $this->seedDangerousAccounts();

        $response = $this->actingAs($this->owner)->get('/marlin/accounts/export');
        $response->assertRedirect();

        $operation = TenantContext::run(
            $this->marlin,
            fn () => BulkOperation::query()->where('resource_type', 'accounts')->firstOrFail()
        );
        $this->clearDatabaseTenantContext();

        $this->artisan('queue:work', ['--queue' => 'bulk', '--once' => true, '--no-interaction' => true]);

        $operation->refresh();
        $this->assertSame(BulkOperation::STATUS_COMPLETED, $operation->status);

        $csv = Storage::disk('local')->get($operation->result_path);

        $this->assertEscapedForEveryPrefix($this->parseCsv($csv));
    }

    private function seedDangerousAccounts(): void
    {
        TenantContext::run($this->marlin, function (): void {
            foreach (self::DANGEROUS_PREFIXES as $key => $prefix) {
                (new AccountFactory)->create([
                    'created_by' => $this->owner->getKey(),
                    'name' => $prefix.'cmd|/bin/calc '.$key,
                    'domain' => "danger-{$key}.example.com",
                ]);
            }
        });
        $this->clearDatabaseTenantContext();
    }

    /**
     * @param  list<list<string>>  $rows
     */
    private function assertEscapedForEveryPrefix(array $rows): void
    {
        foreach (self::DANGEROUS_PREFIXES as $key => $prefix) {
            $row = $this->findRowByDomain($rows, "danger-{$key}.example.com");

            $this->assertNotNull($row, "Rândul pentru prefixul \"{$key}\" lipsește din CSV.");
            $this->assertSame(
                "'".$prefix.'cmd|/bin/calc '.$key,
                $row[0],
                "Prefixul \"{$key}\" nu a fost neutralizat cu apostrof în celula \"Name\"."
            );
        }
    }

    /**
     * @return list<list<string>>
     */
    private function parseCsv(string $content): array
    {
        $lines = array_filter(explode("\n", trim($content)), fn (string $line) => $line !== '');

        return array_map(fn (string $line) => str_getcsv($line), $lines);
    }

    /**
     * @param  list<list<string>>  $rows
     * @return list<string>|null
     */
    private function findRowByDomain(array $rows, string $domain): ?array
    {
        foreach ($rows as $row) {
            if (($row[1] ?? null) === $domain) {
                return $row;
            }
        }

        return null;
    }
}
