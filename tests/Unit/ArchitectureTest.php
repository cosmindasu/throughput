<?php

namespace Tests\Unit;

use App\Models\BulkOperationChunk;
use App\Models\IdempotencyKey;
use App\Models\SavedViewDefault;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;

/**
 * Reguli pe care code review-ul le-ar prinde în zilele bune și le-ar rata în celelalte.
 *
 * Nu extinde `Tests\TestCase`: nu are nevoie de bază de date, iar o suită arhitecturală
 * care pornește PostgreSQL ca să citească fișiere ar fi tocmai genul de cost inutil pe care
 * îl evită tot restul proiectului.
 */
class ArchitectureTest extends TestCase
{
    public function test_the_eloquent_tenant_scope_is_bypassed_in_exactly_one_place(): void
    {
        $offenders = [];

        foreach ($this->phpFilesIn(__DIR__.'/../../app') as $file) {
            $code = $this->codeWithoutComments($file);

            // Listă ALBĂ, nu neagră: orice `withoutGlobalScope(...)` numără ca ofensator, cu o
            // singură excepție, `NotAnonymizedContactScope::class` (specs.md §20.5, contactul
            // principal anonimizat afișat pe deal). O listă neagră pe `TenantScope::class` ar fi
            // lăsat să treacă forma cu namespace complet sau un alias de import.
            // `withoutGlobalScopes()` le scoate pe TOATE, deci și pe cel de tenant.
            $bypassesTenantScope = str_contains($code, 'withoutGlobalScopes(');

            preg_match_all('/withoutGlobalScope\s*\(([^)]*)\)/', $code, $matches);

            foreach ($matches[1] as $argument) {
                if (trim($argument) !== 'NotAnonymizedContactScope::class') {
                    $bypassesTenantScope = true;
                }
            }

            if ($bypassesTenantScope) {
                $offenders[] = $this->relative($file);
            }
        }

        // Singurul loc legitim e `Membership::forCurrentUserAcrossTenants()`, pentru
        // comutatorul de workspace (ADR-014, pct. 2) — o interogare cross-tenant prin
        // natura ei. Orice al doilea loc are nevoie de un ADR, nu de un commit.
        $this->assertSame(
            ['app/Models/Membership.php'],
            $offenders,
            'Ocolirea global scope-ului de tenant e permisă doar în Membership::forCurrentUserAcrossTenants().'
        );
    }

    public function test_env_is_read_only_inside_config_files(): void
    {
        $offenders = [];

        foreach ($this->phpFilesIn(__DIR__.'/../../app') as $file) {
            if (preg_match('/(?<![>\w:])env\s*\(/', $this->codeWithoutComments($file))) {
                $offenders[] = $this->relative($file);
            }
        }

        // După `config:cache` (pe care entrypoint-ul de producție îl rulează), `env()`
        // întoarce implicitul. Un `env('DEMO_MODE')` în cod s-ar fi stins tăcut exact în
        // singurul mediu unde contează — vezi comentariul din config/throughput.php.
        $this->assertSame([], $offenders, 'Citește prin config(), nu prin env(), în afara fișierelor de configurare.');
    }

    public function test_tenant_context_is_set_through_the_single_gate(): void
    {
        $offenders = [];

        foreach ($this->phpFilesIn(__DIR__.'/../../app') as $file) {
            $relative = $this->relative($file);

            if ($relative === 'app/Services/Tenancy/TenantContext.php') {
                continue;
            }

            if (str_contains($this->codeWithoutComments($file), 'set_config')) {
                $offenders[] = $relative;
            }
        }

        // ADR-014, pct. 3: o singură poartă. Trei locuri care setează contextul înseamnă
        // trei feluri de a-l seta greșit — și exact așa arăta codul înainte de ADR.
        $this->assertSame([], $offenders, 'Contextul se setează doar prin TenantContext.');
    }

    /**
     * FR-I18N-04, ADR-022 — golul pe care `php artisan i18n:coverage` NU-l poate acoperi,
     * din construcție: gate-ul ăla compară `lang/en/*.php` cu `lang/fr/*.php` ÎNTRE ELE, deci
     * dovedește doar că cele două cataloage sunt SIMETRICE — niciodată că un text anume a
     * AJUNS într-un catalog. Un literal englez scris direct într-un sink de mesaj (nu
     * `trans()`/`__()`) e complet invizibil pentru comparația aia: n-are nicio cheie de
     * comparat, deci nu apare nici ca „lipsă", nici ca „orfană". Trei treceri succesive ale
     * Lotului I18N (Val 2 → Val 5) au reparat exact acest fel de literal, găsit manual de
     * fiecare dată, câte puțin mai mult decât ultima trecere — semn că vânătoarea manuală nu
     * converge. Testul de față înlocuiește vânătoarea cu o listă exhaustivă, mecanică.
     *
     * **De ce AST (`nikic/php-parser`), nu regex.** Un regex peste sursă („caută un șir
     * englez lângă `->add(`") ar reproduce exact clasa de fals-pozitive pe care proiectul a
     * plătit-o deja de două ori în acest lot — un comentariu care EXPLICĂ regula, un nume de
     * variabilă, un cuvânt cheie de câmp cu spațiu într-un mesaj de eroare tehnic — toate ar
     * arăta „ca text" pentru un regex, dar nu și pentru un parser care vede exact ce vede
     * PHP-ul: apeluri de metodă, argumente, literale de șir. `ArchitectureTest` de mai sus
     * folosește deja regex pentru DOUĂ verificări (linia 33, linia 61) — funcționează acolo
     * fiindcă acelea caută un NUME (`withoutGlobalScope(`, `env(`), nu proză; aici căutăm
     * proză, unde un regex n-are cum să distingă „text pentru utilizator" de „orice altceva
     * care conține litere și un spațiu".
     *
     * **Sink-urile sunt ÎNGUSTE prin construcție** — proiectul produce mesaje pentru
     * utilizator prin exact cinci forme, toate cu un echivalent corect
     * (`trans()`/`__()`/`trans_choice()`):
     *   - `$validator->errors()->add('câmp', <mesaj>)`;
     *   - `ValidationException::withMessages(['câmp' => <mesaj>])`;
     *   - `$fail(<mesaj>)`, din închiderea unei reguli de validare personalizate;
     *   - `abort($cod, <mesaj>)`;
     *   - `return [...]` dintr-o metodă `messages()`/`attributes()` de `FormRequest`.
     * Deci **orice literal de șir găsit ca argument/valoare într-unul din locurile astea e
     * o încălcare, nu un candidat de triat manual** — nu există euristică pe „pare
     * englezesc", fiindcă sink-ul însuși e semnalul, nu conținutul.
     *
     * O valoare care conține interpolare (`"...{$var}..."`) sau concatenare (`'...'.$var`)
     * e la fel de gravă — un literal cu o gaură în mijloc tot literal e — și e semnalată ca
     * atare (marcată `<interpolat>` în mesajul de eroare, cu variabilele mascate).
     *
     * O valoare fără niciun spațiu (`'câmp'`, `'email'`) nu e proză — e un NUME (cheie de
     * câmp în `messages()`/`attributes()`, primul argument al lui `errors()->add()`) — și e
     * ignorată deliberat, altfel testul ar semnala fiecare `FormRequest` din proiect.
     *
     * **Excepțiile sunt o listă EXPLICITĂ**, `sinkExceptions()` mai jos — fiecare intrare
     * poartă motivul lângă ea, verificat, nu presupus. O excepție nouă trebuie adăugată
     * acolo, cu motivul ei, nu strecurată tăcut printr-o reformulare care scapă sink-urilor
     * de mai sus.
     */
    public function test_user_facing_message_sinks_never_carry_a_raw_string_literal(): void
    {
        $offenders = [];

        foreach ($this->phpFilesIn(__DIR__.'/../../app') as $file) {
            $relative = $this->relative($file);
            $code = file_get_contents($file->getPathname());

            foreach ($this->sinkHitsIn($code) as $hit) {
                if ($this->isExceptedSink($relative, $hit)) {
                    continue;
                }

                $offenders[] = sprintf(
                    '%s:%d  [%s]  %s',
                    $relative,
                    $hit['line'],
                    $hit['sink'],
                    $hit['text'],
                );
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "Literal englez găsit direct într-un sink de mesaj pentru utilizator (FR-I18N-04).\n"
            ."Forma corectă e mereu trans()/__() — vezi lang/en/rules.php pentru domeniul potrivit.\n"
            .'Fiecare linie de mai jos dă fișierul, linia, sink-ul și textul — repară de acolo:'."\n"
            .implode("\n", $offenders),
        );
    }

    /**
     * BR-DATA-01 — entitățile expuse prin API/URL folosesc ULID ca cheie primară, niciodată
     * auto-increment (enumerare de ID-uri secvențiale, OWASP API3:2023). `HasUlids` singur nu
     * e suficient de verificat prin `grep` („folosește trait-ul undeva în fișier" ar trece și
     * pe un model care îl importă dar nu îl pune în `use`) — verificarea corectă e prin
     * reflecție, pe instanța reală a modelului: trait-ul chiar prezent în `class_uses_recursive()`,
     * și cele două metode pe care Eloquent le consultă efectiv la INSERT/route-binding,
     * `getIncrementing()`/`getKeyType()` — `HasUniqueStringIds::getIncrementing()`/`getKeyType()`
     * le suprascriu condiționat, pe `uniqueIds()`, deci un model care redefinește
     * `getKeyName()` fără să-l adauge la `uniqueIds()` ar avea trait-ul „folosit" în cod, dar
     * comportamentul tot pe auto-increment.
     *
     * Lista de modele vine din `app/Models/*.php` (neredundant cu Scopes/, care nu extinde
     * `Model`), MINUS o listă albă explicită — `modelsNeverExposedThroughAUrl()` — nu o listă
     * neagră: un model NOU nu e verificat „din greșeală mai puțin", ci implicit VERIFICAT,
     * până când cineva adaugă o excludere motivată.
     */
    public function test_url_and_api_exposed_models_use_ulid_primary_keys(): void
    {
        $modelClasses = $this->modelClasses();

        // Gardă anti-„vacuous truth": dacă scanarea directorului s-ar rupe (cale greșită,
        // model mutat), bucla de mai jos ar trece verde fără să verifice nimic.
        $this->assertGreaterThan(25, count($modelClasses), 'app/Models/ pare incomplet scanat — verifică calea.');

        $offenders = [];

        foreach ($modelClasses as $class) {
            if (in_array($class, $this->modelsNeverExposedThroughAUrl(), true)) {
                continue;
            }

            $model = new $class;

            $usesHasUlids = in_array(HasUlids::class, class_uses_recursive($class), true);
            $incrementing = $model->getIncrementing();
            $keyType = $model->getKeyType();

            if (! $usesHasUlids || $incrementing !== false || $keyType !== 'string') {
                $offenders[] = sprintf(
                    '%s (HasUlids=%s, incrementing=%s, keyType=%s)',
                    $class,
                    $usesHasUlids ? 'da' : 'NU',
                    $incrementing ? 'true' : 'false',
                    $keyType,
                );
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "Model expus prin rută/API fără cheie primară ULID (BR-DATA-01):\n".implode("\n", $offenders),
        );
    }

    /**
     * Listă ALBĂ, motivată individual — fiecare intrare verificată prin grep pe `app/`
     * (2026-09-22), nu presupusă. Niciuna nu e un pivot Eloquent sau o tabelă de framework —
     * proiectul n-are pivoturi de tip `Pivot` printre modelele lui — ci trei modele interne a
     * căror cheie primară proprie nu ajunge NICIODATĂ într-un răspuns HTTP:
     *
     * - `IdempotencyKey` — adresat exclusiv prin coloana `key` (header-ul clientului,
     *   `RequireIdempotencyKey`); `$existing->id`/`->getKey()` nu apare în niciun răspuns —
     *   corpul memorat e cel al ENDPOINT-ULUI original (ex. `id`-ul comenzii), nu al rândului
     *   de idempotență.
     * - `SavedViewDefault` — `SavedViewController` întoarce `saved_view_id` (FK către
     *   `SavedView`, alt model, deja verificat separat), niciodată `$default->id`/`->getKey()`
     *   propriu.
     * - `BulkOperationChunk` — bookkeeping intern per-chunk (`ProcessBulkChunkJob`,
     *   `BulkChunkAction`); `BulkOperationResource` expune `id`-ul lui `BulkOperation` (rândul
     *   PĂRINTE), niciun `Resource` sau răspuns JSON din `app/Http/` nu atinge un chunk.
     *
     * O interogare/`Resource` nouă care serializează `id`-ul unuia din aceste modele trebuie
     * să-l scoată din listă, nu să extindă excepția tăcut.
     *
     * @return list<class-string<Model>>
     */
    private function modelsNeverExposedThroughAUrl(): array
    {
        return [
            IdempotencyKey::class,
            SavedViewDefault::class,
            BulkOperationChunk::class,
        ];
    }

    /**
     * Toate clasele Eloquent direct în `app/Models/` (neredundant, nerecursiv — `Scopes/` nu
     * conține modele). `glob()`, nu `phpFilesIn()` de mai jos: acela e recursiv prin design,
     * pentru verificările pe tot `app/`, și ar coborî și în `Scopes/`.
     *
     * @return list<class-string<Model>>
     */
    private function modelClasses(): array
    {
        $classes = [];

        foreach (glob(__DIR__.'/../../app/Models/*.php') ?: [] as $file) {
            $class = 'App\\Models\\'.basename($file, '.php');

            if (! class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);

            if ($reflection->isAbstract() || ! $reflection->isSubclassOf(Model::class)) {
                continue;
            }

            $classes[] = $class;
        }

        sort($classes);

        return $classes;
    }

    /**
     * Excepțiile listei de mai sus — fiecare cu motivul ei, verificat, nu presupus.
     * Potrivire pe (fișier, sink, text EXACT), nu doar pe fișier: o excepție nu acoperă
     * tăcut un literal NOU adăugat mai târziu în același fișier.
     *
     * @return list<array{file: string, sink: string, text: string, reason: string}>
     */
    private function sinkExceptions(): array
    {
        return [
            [
                'file' => 'app/Http/Controllers/Web/Auth/PasswordResetLinkController.php',
                'sink' => 'abort',
                'text' => 'Too many password reset requests. Please try again later.',
                // Verificat direct în vendor/laravel/framework, nu presupus: proiectul n-are
                // `resources/views/errors/429.blade.php`, deci Laravel randează
                // `vendor/laravel/framework/.../Exceptions/views/429.blade.php`, care
                // hardcodează `__('Too Many Requests')` pe `@section('message', ...)` și nu
                // atinge NICIODATĂ `$exception->getMessage()`. Mesajul de mai sus există doar
                // pentru loguri/`report()` — nu ajunge pe ecran, deci nu e o încălcare
                // FR-I18N-04 de reparat, ci text mort de interfață.
                'reason' => 'abort(429, …) — mesajul nu se randează niciodată: vederea 429 a framework-ului '
                    ."hardcodează __('Too Many Requests') și ignoră mesajul excepției (verificat în vendor/).",
            ],
        ];
    }

    /**
     * @param  array{line: int, sink: string, text: string}  $hit
     */
    private function isExceptedSink(string $relativeFile, array $hit): bool
    {
        foreach ($this->sinkExceptions() as $exception) {
            if ($exception['file'] === $relativeFile
                && $exception['sink'] === $hit['sink']
                && $exception['text'] === $hit['text']) {
                return true;
            }
        }

        return false;
    }

    /**
     * Parcurge sursa cu `nikic/php-parser` și întoarce fiecare literal de șir găsit ca
     * argument/valoare într-unul din cele cinci sink-uri de mesaj (vezi docblock-ul
     * testului). O sursă cu erori de sintaxă (n-ar trebui să existe în `app/`) e sărită
     * tăcut — nu e treaba testului ăstuia să prindă cod care nu compilează.
     *
     * @return list<array{line: int, sink: string, text: string}>
     */
    private function sinkHitsIn(string $code): array
    {
        $parser = (new ParserFactory)->createForNewestSupportedVersion();

        try {
            $ast = $parser->parse($code);
        } catch (\Throwable) {
            return [];
        }

        if ($ast === null) {
            return [];
        }

        $visitor = new class extends NodeVisitorAbstract
        {
            /** @var list<array{line: int, sink: string, text: string}> */
            public array $found = [];

            public function enterNode(Node $node)
            {
                // $validator->errors()->add('câmp', <literal>)
                if ($node instanceof Node\Expr\MethodCall
                    && $node->name instanceof Node\Identifier
                    && $node->name->toString() === 'add'
                    && isset($node->args[1])
                    && $node->args[1] instanceof Node\Arg) {
                    $this->collect($node->args[1]->value, 'errors()->add', $node->getStartLine());
                }

                // ValidationException::withMessages(['câmp' => <literal>])
                if ($node instanceof Node\Expr\StaticCall
                    && $node->name instanceof Node\Identifier
                    && $node->name->toString() === 'withMessages'
                    && isset($node->args[0])
                    && $node->args[0] instanceof Node\Arg
                    && $node->args[0]->value instanceof Node\Expr\Array_) {
                    foreach ($node->args[0]->value->items as $item) {
                        if ($item !== null) {
                            $this->collect($item->value, 'withMessages', $item->getStartLine());
                        }
                    }
                }

                // $fail(<literal>) — închiderea unei reguli de validare personalizate.
                if ($node instanceof Node\Expr\FuncCall
                    && $node->name instanceof Node\Expr\Variable
                    && $node->name->name === 'fail'
                    && isset($node->args[0])
                    && $node->args[0] instanceof Node\Arg) {
                    $this->collect($node->args[0]->value, '$fail()', $node->getStartLine());
                }

                // abort($cod, <literal>)
                if ($node instanceof Node\Expr\FuncCall
                    && $node->name instanceof Node\Name
                    && $node->name->toString() === 'abort'
                    && isset($node->args[1])
                    && $node->args[1] instanceof Node\Arg) {
                    $this->collect($node->args[1]->value, 'abort', $node->getStartLine());
                }

                // public function messages()/attributes(): array { return ['regulă' => <literal>]; }
                if ($node instanceof Node\Stmt\ClassMethod
                    && in_array($node->name->toString(), ['messages', 'attributes'], true)) {
                    foreach ($node->stmts ?? [] as $stmt) {
                        if ($stmt instanceof Node\Stmt\Return_ && $stmt->expr instanceof Node\Expr\Array_) {
                            foreach ($stmt->expr->items as $item) {
                                if ($item !== null) {
                                    $this->collect($item->value, 'messages()', $item->getStartLine());
                                }
                            }
                        }
                    }
                }

                return null;
            }

            private function collect(Node $value, string $sink, int $line): void
            {
                $text = null;

                if ($value instanceof Node\Scalar\String_) {
                    $text = $value->value;
                } elseif ($value instanceof Node\Scalar\InterpolatedString || $value instanceof Node\Expr\BinaryOp\Concat) {
                    $text = '<interpolat> '.$this->flatten($value);
                }

                if ($text === null) {
                    return;
                }

                // Ignoră ce nu e proză: nume de câmp, chei, fragmente scurte fără spațiu.
                if (! str_contains(trim($text), ' ')) {
                    return;
                }

                $this->found[] = ['line' => $line, 'sink' => $sink, 'text' => $text];
            }

            private function flatten(Node $node): string
            {
                if ($node instanceof Node\Scalar\String_) {
                    return $node->value;
                }

                if ($node instanceof Node\Expr\BinaryOp\Concat) {
                    return $this->flatten($node->left).$this->flatten($node->right);
                }

                if ($node instanceof Node\Scalar\InterpolatedString) {
                    $out = '';
                    foreach ($node->parts as $part) {
                        $out .= $part instanceof Node\InterpolatedStringPart ? $part->value : '${…}';
                    }

                    return $out;
                }

                return '…';
            }
        };

        $traverser = new NodeTraverser;
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        return $visitor->found;
    }

    /**
     * Codul fără comentarii.
     *
     * Prima variantă a acestor verificări citea fișierul brut și pica pe COMENTARIILE care
     * explicau tocmai regula („citit din config, niciodată din env()") — un test care
     * pedepsește documentarea regulii pe care o impune nu rezistă nici o săptămână.
     */
    private function codeWithoutComments(SplFileInfo $file): string
    {
        $code = '';

        foreach (token_get_all(file_get_contents($file->getPathname())) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $code .= is_array($token) ? $token[1] : $token;
        }

        return $code;
    }

    /**
     * @return list<SplFileInfo>
     */
    private function phpFilesIn(string $directory): array
    {
        $files = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory)) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file;
            }
        }

        usort($files, fn (SplFileInfo $a, SplFileInfo $b) => strcmp($a->getPathname(), $b->getPathname()));

        return $files;
    }

    private function relative(SplFileInfo $file): string
    {
        $root = realpath(__DIR__.'/../../').'/';

        return str_replace($root, '', $file->getRealPath());
    }
}
