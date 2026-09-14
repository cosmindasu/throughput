<?php

namespace App\Actions\Orders;

use App\Actions\Stock\Concerns\LocksInventoryLevels;
use App\Enums\OrderStatus;
use App\Models\Location;
use App\Models\Order;
use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * `draft -> confirmed` (§11.2 pas 3, §11.3, BR-ORD-02, BR-STOCK-04) — singurul loc care
 * scrie `reserved`, genera `order_number` și setează `placed_at`.
 *
 * Simplificare asumată, raportată în livrabil: rezervarea se face la locația implicită
 * a tenantului (`locations.is_default = true`), exact ca onorarea din seed
 * (`StockAndOrdersSeeder`: „toate comenzile se onorează din locația principală").
 * `order_lines` nu are propria coloană de locație (schema Fazei 1 nu prevede una la
 * acest nivel de granularitate — alegerea unei locații per linie rămâne pentru
 * fluxul de shipment, valul 2, unde `shipments.location_id` există deja).
 */
final class ConfirmOrderAction
{
    use LocksInventoryLevels;

    /** Continuă numerotarea seed-ului (`StockAndOrdersSeeder::$orderNumber = 10000`). */
    private const FIRST_SEQUENCE = 10000;

    public function execute(Order $order, bool $acknowledgeBackorder): Order
    {
        return DB::transaction(function () use ($order, $acknowledgeBackorder): Order {
            /** @var Order $locked */
            $locked = Order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->status->canTransitionTo(OrderStatus::Confirmed)) {
                throw ValidationException::withMessages([
                    'status' => "This order can't be confirmed from its current status ({$locked->status->label()}).",
                ]);
            }

            if ($locked->account_id === null) {
                throw ValidationException::withMessages([
                    'account_id' => 'This order needs a valid account before it can be confirmed.',
                ]);
            }

            $lines = $locked->orderLines()->orderBy('variant_id')->get();

            if ($lines->isEmpty()) {
                throw ValidationException::withMessages([
                    'lines' => 'Add at least one line before confirming this order.',
                ]);
            }

            $location = Location::query()->where('is_default', true)->first()
                ?? Location::query()->oldest('created_at')->firstOrFail();

            $variantIds = $lines->pluck('variant_id')->unique()->values();

            // Convenția comună cu lotul Stoc (`LocksInventoryLevels`, „blocarea pe
            // stoc"): ORICE cod care blochează rânduri `inventory_levels` o face
            // într-o SINGURĂ interogare ordonată `ORDER BY variant_id, location_id`,
            // înainte de orice scriere — ca două tranzacții care ating variante
            // suprapuse să nu se blocheze reciproc în ordine inversă (deadlock clasic
            // pe blocări multiple). Lotul ăsta scrie DOAR `reserved`; lotul Stoc scrie
            // doar `on_hand` — niciodată aceeași coloană din două locuri.
            //
            // `lockLevelsAtLocation()` creează întâi, cu `insertOrIgnore()` sortat, ORICE
            // rând `inventory_levels` lipsă pentru perechile cerute (code review P1-003:
            // o comandă cu DOUĂ linii pe aceeași variantă fără proiecție încă dădea
            // `UniqueConstraintViolationException` la a doua, fiindcă doar colecția din
            // memorie era completată, nu și rândul din bază) — apoi blochează.
            $levels = $this->lockLevelsAtLocation($location->getKey(), $variantIds->all());

            // BR-STOCK-04 / code review P1-002 — cererea se agregă PE VARIANTĂ înainte de
            // comparație: două linii de 6 pe aceeași variantă, cu `available = 10`, cer
            // împreună 12, deci depășesc — comparate separat (6 vs 10, 6 vs 10) niciuna
            // n-ar fi părut peste stoc, iar `reserved` ar fi ajuns la 12 fără backorder
            // confirmat.
            $requestedByVariant = $lines
                ->groupBy('variant_id')
                ->map(fn ($linesForVariant) => (int) $linesForVariant->sum('quantity'));

            $needsBackorder = false;

            foreach ($requestedByVariant as $variantId => $requested) {
                $level = $levels->get($variantId);
                $available = $level !== null ? $level->on_hand - $level->reserved : 0;

                if ($requested > $available) {
                    $needsBackorder = true;
                }
            }

            // BR-STOCK-04 — nici blocare, nici permitere tăcută: dacă vreo variantă cere
            // mai mult decât `available`, confirmarea are nevoie de flagul explicit,
            // verificat AICI, de server (`ConfirmOrderRequest::acknowledgesBackorder()`),
            // nu doar afișat de interfață.
            if ($needsBackorder && ! $acknowledgeBackorder) {
                throw ValidationException::withMessages([
                    'acknowledge_backorder' => 'One or more lines exceed the available stock. Confirm explicitly to place this order as a backorder.',
                ]);
            }

            foreach ($lines as $line) {
                // Garantat de `lockLevelsAtLocation()` mai sus — fiecare variantă din
                // `$variantIds` are acum un rând `inventory_levels`, creat dacă lipsea.
                $levels->get($line->variant_id)->increment('reserved', $line->quantity);
            }

            $locked->order_number = $this->nextOrderNumber();
            $locked->status = OrderStatus::Confirmed;
            $locked->placed_at = now();
            $locked->save();

            return $locked->fresh(['account', 'contact', 'owner', 'orderLines.variant']);
        });
    }

    /**
     * BR-ORD-02 — secvențial per tenant, generat LA CONFIRMARE. Concurență: două
     * confirmări simultane, pe ACEEAȘI tranzacție HTTP-scoped care ține deja
     * `lockForUpdate()` pe rândul `orders` de mai sus, NU sunt suficiente ca să
     * serializeze acest calcul — comenzi DIFERITE nu se ceartă pe același rând
     * `orders`, deci ambele ar putea citi „MAX = 10042" înainte ca oricare să
     * scrie, exact cursa clasică „SELECT MAX() + 1" (un `UPDATE`/`INSERT`
     * concurent pe rândul citit de `SELECT ... FOR UPDATE` nu blochează un al
     * doilea `SELECT ... FOR UPDATE` care încă n-a ajuns la acel rând — lock-ul
     * de mai sus protejează STAREA comenzii, nu numărătoarea tenantului).
     *
     * Garanția vine din blocarea rândului `tenants` (nu al unui contor dedicat —
     * schema Fazei 1 nu are unul, iar migrațiile sunt înghețate în faza asta):
     * EXACT tiparul deja stabilit în proiect pentru „serializează o invariantă
     * care nu trăiește pe un singur rând ținta" — `PrimaryContactAssignment`
     * blochează `Account` ca să serializeze unicitatea `is_primary`,
     * `SaveStageAction` blochează `Pipeline` ca să serializeze poziția etapelor.
     * Aici: blocarea `Tenant` serializează TOATE confirmările acestui tenant —
     * a doua tranzacție așteaptă la lock-ul de mai jos până la commit-ul primei,
     * apoi recalculează MAX-ul cu o interogare NOUĂ (Postgres, READ COMMITTED —
     * implicit, neschimbat în acest proiect — dă fiecărei instrucțiuni o poză
     * proaspătă a bazei, nu doar tranzacției), deci vede deja numărul scris de
     * prima. Unicitatea rămâne garantată și dacă acest raționament ar avea o
     * gaură: `orders_tenant_id_order_number_unique` ar respinge orice coliziune
     * cu o eroare de bază de date, nu cu o comandă dublu-numerotată tăcut.
     *
     * Code review P1-001 — `->lock('for no key update')`, NU `lockForUpdate()`
     * (`FOR UPDATE`). Tranzacția asta ține toată cererea HTTP; `FOR UPDATE` intră
     * în conflict cu `FOR KEY SHARE`, blocarea pe care PostgreSQL o ia la verificarea
     * FK a oricărui INSERT/UPDATE într-o tabelă copil (orice rând cu `tenant_id`),
     * deci oprea toate scrierile tenantului până la commit — măsurat cu două sesiuni,
     * `lock timeout` pe `SELECT 1 FROM ONLY tenants … FOR KEY SHARE`. `FOR NO KEY
     * UPDATE` serializează la fel două confirmări (rândul `tenants` rămâne blocat),
     * dar lasă să treacă inserările în tabelele copil. Vezi `.ai/rules/tenancy.md`,
     * secțiunea „Blocarea unui rând părinte".
     */
    private function nextOrderNumber(): string
    {
        // `TenantScope::CONTAINER_KEY` ('tenant.id'), NU `app('tenant')` (modelul): al
        // doilea e legat DOAR de `ResolveWorkspace`, pe cererea HTTP — indisponibil
        // pentru un apel direct al acțiunii (teste, joburi). Primul e legat identic de
        // `TenantContext::run()`/`openFor()`, peste tot (ADR-014).
        $tenantId = TenantScope::requireCurrentTenantId();

        /** @var Tenant $tenant */
        $tenant = Tenant::query()->whereKey($tenantId)->lock('for no key update')->firstOrFail();

        $sample = Order::query()->whereNotNull('order_number')->value('order_number');
        $prefix = is_string($sample) && preg_match('/^(.*)-\d+$/', $sample, $matches) === 1
            ? $matches[1]
            : $this->prefixFromSlug($tenant->slug);

        $maxNumber = Order::query()
            ->whereNotNull('order_number')
            ->selectRaw("max(substring(order_number from '[0-9]+$')::integer) as n")
            ->value('n');

        $next = $maxNumber !== null ? ((int) $maxNumber) + 1 : self::FIRST_SEQUENCE;

        return "{$prefix}-{$next}";
    }

    private function prefixFromSlug(string $slug): string
    {
        $letters = strtoupper((string) preg_replace('/[^a-zA-Z]/', '', $slug));

        return $letters !== '' ? substr($letters, 0, 3) : 'ORD';
    }
}
