<?php

namespace Database\Seeders\Support;

use Illuminate\Console\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Persistarea în bloc din plan §7.8: definițiile plauzibile vin din factories
 * (`definition()`), scrierea e prin `Model::insert()` pe chunk-uri de ~1000 — mecanismul
 * ocolește deliberat evenimentele Eloquent (viteză, la sute de mii de rânduri), deci
 * APELANTUL răspunde de `id`/`tenant_id` explicite pe fiecare rând (`BelongsToTenant` și
 * `HasUlids` nu se declanșează pe inserare în bloc).
 */
final class ChunkedWriter
{
    /** @var list<array<string, mixed>> */
    private array $buffer = [];

    private int $written = 0;

    private bool $flushed = false;

    private ?ProgressBar $bar = null;

    private ?OutputInterface $output = null;

    /** @var list<self> */
    private array $parents = [];

    public function __construct(
        private readonly string $modelClass,
        private readonly int $chunkSize = 1000,
        ?Command $command = null,
        ?string $label = null,
        int $total = 0,
    ) {
        if ($command !== null && $label !== null) {
            $this->output = $command->getOutput();
            $this->output->writeln("  <fg=cyan>›</> {$label}");
            $this->bar = $this->output->createProgressBar(max($total, 1));
            $this->bar->setFormat(' %current%/%max% [%bar%] %percent:3s%%');
            $this->bar->start();
        }
    }

    /** @param array<string, mixed> $row */
    public function push(array $row): void
    {
        $this->buffer[] = $row;
        $this->bar?->advance();

        if (count($this->buffer) >= $this->chunkSize) {
            $this->flushChunk();
        }
    }

    /**
     * Declară scrierile de care depinde aceasta prin chei străine.
     *
     * Fără ea, un writer-copil își golește bufferul când se umple EL, iar părintele poate
     * fi încă în buffer: contactele se umplu mai repede decât conturile (≈1,3 per cont),
     * deci la 1.000 de contacte conturile lor sunt încă nescrise și PostgreSQL respinge
     * inserarea cu `violates foreign key constraint`. Nu se vede la volum mic — sub o mie
     * de rânduri nu se golește nimic până la final, în ordinea corectă — deci bug-ul apare
     * abia pe setul real. Măsurat: 40 de conturi trec, 1.200 cad.
     */
    public function dependsOn(self ...$parents): self
    {
        $this->parents = array_values($parents);

        return $this;
    }

    /** Scrie ce s-a adunat, fără să atingă bara de progres (folosit de copii). */
    public function flushBuffer(): void
    {
        $this->flushChunk();
    }

    private function flushChunk(): void
    {
        if ($this->buffer === []) {
            return;
        }

        // Întâi părinții — recursiv, deci un lanț (comandă → linie → linie de expediere)
        // se rezolvă singur, în ordine topologică.
        foreach ($this->parents as $parent) {
            $parent->flushBuffer();
        }

        try {
            $this->modelClass::insert($this->buffer);
        } catch (Throwable $e) {
            // Bufferul se golește ȘI la eșec, deliberat: altfel destructorul de mai jos
            // reîncearcă aceeași inserare după ce contextul de tenant s-a închis, iar
            // eroarea care apare în log e „niciun tenant în context" — adică simptomul
            // plasei de siguranță, nu cauza. Exact așa s-a pierdut o oră aici.
            $this->buffer = [];

            throw $e;
        }

        $this->written += count($this->buffer);
        $this->buffer = [];
    }

    public function flush(): void
    {
        $this->flushChunk();

        if ($this->bar !== null && ! $this->flushed) {
            $this->bar->finish();
            $this->output?->writeln('');
        }

        $this->flushed = true;
    }

    public function written(): int
    {
        return $this->written;
    }

    // Deliberat FĂRĂ `__destruct()`: un flush automat la distrugerea obiectului ar rula și
    // în timpul derulării unei excepții (PHP distruge obiectele ieșite din scop pe măsură
    // ce stiva se derulează) — adică exact când tranzacția e deja marcată "aborted" de
    // Postgres, iar un INSERT în plus ar înlocui excepția REALĂ cu un
    // "25P02: current transaction is aborted", mult mai greu de diagnosticat (găsit pe
    // bază reală, nu presupus). Codul apelant CHEAMĂ `flush()` explicit, la finalul căii
    // fericite — un buffer nescris pe calea de eroare oricum se pierde odată cu
    // ROLLBACK-ul tranzacției tenantului, deci nu era "salvat" de plasa de mai jos.
}
