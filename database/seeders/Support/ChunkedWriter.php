<?php

namespace Database\Seeders\Support;

use Illuminate\Console\Command;
use Symfony\Component\Console\Helper\ProgressBar;

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

    public function __construct(
        private readonly string $modelClass,
        private readonly int $chunkSize = 1000,
        ?Command $command = null,
        ?string $label = null,
        int $total = 0,
    ) {
        if ($command !== null && $label !== null) {
            $command->getOutput()->writeln("  <fg=cyan>›</> {$label}");
            $this->bar = $command->getOutput()->createProgressBar(max($total, 1));
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

    private function flushChunk(): void
    {
        if ($this->buffer === []) {
            return;
        }

        $this->modelClass::insert($this->buffer);
        $this->written += count($this->buffer);
        $this->buffer = [];
    }

    public function flush(): void
    {
        $this->flushChunk();

        if ($this->bar !== null && ! $this->flushed) {
            $this->bar->finish();
            $this->bar->getOutput()->writeln('');
        }

        $this->flushed = true;
    }

    public function written(): int
    {
        return $this->written;
    }

    public function __destruct()
    {
        // Plasă de siguranță — codul apelant TREBUIE să cheme flush() explicit (ca să
        // închidă și bara de progres la locul potrivit), dar un buffer nescris nu trebuie
        // niciodată pierdut silențios dacă cineva a omis apelul.
        if ($this->buffer !== []) {
            $this->flushChunk();
        }
    }
}
