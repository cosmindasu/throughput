<?php

namespace Tests\Unit;

use App\Models\BulkOperationChunk;
use App\Models\IdempotencyKey;
use App\Models\SavedViewDefault;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\ScansPhpSource;

/**
 * Reguli pe care code review-ul le-ar prinde în zilele bune și le-ar rata în celelalte.
 *
 * Nu extinde `Tests\TestCase`: nu are nevoie de bază de date, iar o suită arhitecturală
 * care pornește PostgreSQL ca să citească fișiere ar fi tocmai genul de cost inutil pe care
 * îl evită tot restul proiectului.
 */
class ArchitectureTest extends TestCase
{
    use ScansPhpSource;

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

        // `database/` a intrat în scanare abia acum (cerere ulterioară a coordonatorului,
        // 2026-09-23): comanda `demo:reset` și seederele rulează în același proces PHP ca
        // restul aplicației, deci un `env()` direct acolo are exact aceeași boală ca unul
        // în `app/` — moare tăcut după `config:cache`.
        foreach ([__DIR__.'/../../app', __DIR__.'/../../database'] as $directory) {
            foreach ($this->phpFilesIn($directory) as $file) {
                // Prima rulare pe `database/` a prins un `env()` rămas în `CarrierSettingsSeeder`
                // după un fix DOM-04 care schimbase doar docblock-ul — exact golul acoperit acum.
                if (preg_match('/(?<![>\w:])env\s*\(/', $this->codeWithoutComments($file))) {
                    $offenders[] = $this->relative($file);
                }
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
     * Auto-test al detectorului de mai sus, scris la auditul din 2026-09-23 când s-a
     * descoperit că `abort_if`/`abort_unless` treceau neobservate: un detector care n-ar
     * mai găsi nimic ar lăsa garda principală verde fără să verifice nimic.
     */
    public function test_the_message_sink_detector_flags_every_abort_form(): void
    {
        $offending = <<<'PHP'
            <?php
            abort(403, 'You are not allowed here.');
            abort_if($x, 403, 'This account is not a member.');
            abort_unless($y, 404, "Nothing to see {$here} today.");
            PHP;

        $safe = <<<'PHP'
            <?php
            abort(403, __('rules.members.cannot_invite'));
            abort_if($x, 403, __('rules.members.no_workspace'));
            abort_unless($y, 404);
            PHP;

        $this->assertSame(
            ['abort', 'abort_if', 'abort_unless'],
            array_column($this->sinkHitsIn($offending), 'sink'),
        );
        $this->assertSame([], $this->sinkHitsIn($safe));
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

                // abort_if($condiție, $cod, <literal>) / abort_unless(...) — mesajul e al TREILEA
                // argument. Scăpat până la auditul din 2026-09-23: `DashboardController` avea un
                // literal englez pe calea 403, randat ca atare de pagina de eroare temată.
                if ($node instanceof Node\Expr\FuncCall
                    && $node->name instanceof Node\Name
                    && in_array($node->name->toString(), ['abort_if', 'abort_unless'], true)
                    && isset($node->args[2])
                    && $node->args[2] instanceof Node\Arg) {
                    $this->collect($node->args[2]->value, $node->name->toString(), $node->getStartLine());
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
     * TEST-01 recidivat (audit 2026-09-23) — `InvoiceController::forOrder()` reintroducea
     * EXACT bugul deja reparat o dată în `Order::invoice()` (`.ai/rules/tenancy.md`,
     * „`created_at` are precizie 0 — nu ordonează singur nimic"): o interogare manuală cu
     * `->latest('created_at')`, fără niciun tiebreaker, pe o rută complet netestată. Trei
     * recidive ale ACELEAȘI clase de bug (`Order::invoice()` în Faza 5, `forOrder()` acum,
     * plus cele găsite separat de auditul de domeniu — DOM-03/DOM-05) sunt semnul că un
     * comentariu în `tenancy.md` nu ajunge: codul nou tot îl poate ignora fără să știe că
     * există. Testul de față îl transformă în ceva ce PICĂ, nu ceva ce se citește.
     *
     * **Ce verifică, exact** (vezi `createdAtOrderingHitsIn()` mai jos pentru detector):
     * orice `->latest()`/`->oldest()` (fără argument, implicit `created_at`) sau
     * `->latest('created_at')`/`->oldest('created_at')`/`->orderBy('created_at', ...)`/
     * `->orderByDesc('created_at')`, dacă ACELAȘI lanț fluent de apeluri nu conține și un
     * ordering pe `id` (`orderBy('id', ...)`, `orderByDesc('id')`, `latest('id')`,
     * `oldest('id')`) — plus, separat, `latestOfMany()`/`oldestOfMany()` fără `id` printre
     * coloanele lor proprii (`latestOfMany(['created_at', 'id'])` e forma corectă, folosită
     * azi de `Order::invoice()`).
     *
     * **De ce AST, nu regex** — același argument ca la
     * `test_user_facing_message_sinks_never_carry_a_raw_string_literal()` mai sus, dar
     * pentru STRUCTURĂ, nu proză: „conține `orderByDesc('id')` undeva mai jos în fișier" nu
     * înseamnă „în același lanț" — un fișier poate ordona corect o interogare și greșit pe
     * alta, la zece linii distanță. Un regex pe apropiere de linii ar rata asta în ambele
     * sensuri (fals negativ pe lanțuri lungi, fals pozitiv pe lanțuri diferite apropiate).
     * AST-ul reconstituie lanțul real: urcă la apelul cel mai exterior al expresiei fluente,
     * apoi coboară prin `->var` al fiecărui `MethodCall`, colectând fiecare verigă — exact
     * ce ar citi un developer cu ochiul, mecanic.
     *
     * **`changed_at` a intrat în domeniu la 2026-10-05**, când dovada a apărut. Regula cerea
     * „dovadă că bug-ul chiar există acolo", iar o rulare completă a suitei a dat-o:
     * `MoveDealStageActionTest::test_moving_to_a_different_stage_records_an_event_with_the_previous_stage`
     * a picat cu `from_stage_id` null, pentru că `deal_stage_events.changed_at` e
     * `timestamp(0)` (verificat în `information_schema`), iar crearea dealului și mutarea cad
     * în aceeași secundă — deci sunt EGALE la ordonare. Testul trecea de sute de ori și a
     * picat o dată: exact profilul pe care o gardă îl prinde și o rulare nu.
     *
     * **Ce NU verifică, deliberat**: orice altă coloană (`->latest('sent_at')`) — acelea n-au
     * încă dovada, iar extinderea la „orice coloană" ar cere o listă albă mult mai mare.
     *
     * **Baseline, nu listă neagră**: fișierele din `createdAtOrderingBaseline()` sunt debit
     * cunoscut, verificat individual (linie exactă, nu doar fișier) — vezi
     * `test_baseline_of_created_at_ordering_without_id_only_shrinks()` imediat după, care
     * pică dacă o intrare e reparată și lăsată totuși în listă.
     */
    public function test_created_at_ordering_always_has_an_id_tiebreaker_in_the_same_chain(): void
    {
        $offenders = [];

        // ȘI `tests/`: incidentul care a dus la extinderea regulii a fost chiar într-un test
        // (`MoveDealStageActionTest`), iar o gardă care nu se uită unde s-a produs defectul a
        // învățat lecția pe jumătate. `array_merge`, nu `+`: pe chei numerice `+` păstrează
        // primul operand și ar fi aruncat aproape tot `tests/`, în tăcere.
        $files = array_merge(
            $this->phpFilesIn(__DIR__.'/../../app'),
            $this->phpFilesIn(__DIR__.'/../../tests'),
        );

        foreach ($files as $file) {
            $relative = $this->relative($file);
            $code = file_get_contents($file->getPathname());

            foreach ($this->createdAtOrderingHitsIn($code) as $hit) {
                if ($this->isBaselinedCreatedAtOrdering($relative, $hit['line'])) {
                    continue;
                }

                $offenders[] = sprintf('%s:%d  %s', $relative, $hit['line'], $hit['description']);
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "Ordonare pe `created_at` fără tiebreaker pe `id`, în afara baseline-ului cunoscut.\n"
            ."`created_at` are precizie de secundă (timestamp(0)) — două rânduri scrise în\n"
            ."aceeași cerere pot avea valoarea IDENTICĂ, caz în care Postgres nu garantează\n"
            ."nicio ordine între ele. Bug-ul concret, deja reprodus de două ori în acest\n"
            ."proiect: o anulare + reemitere în aceeași secundă întorcea rândul greșit (cel\n"
            ."vechi) exact în cazul care conta (cel mai recent). Adaugă `->orderBy('id', ...)`/\n"
            ."`->orderByDesc('id')`/`->latest('id')`/`->oldest('id')` în ACELAȘI lanț, sau\n"
            ."`->latestOfMany(['created_at', 'id'])`. Fiecare linie de mai jos dă fișierul,\n"
            .'linia și forma exactă găsită:'."\n"
            .implode("\n", $offenders),
        );
    }

    /**
     * Simetric cu restul listelor albe din acest fișier (`sinkExceptions`,
     * `modelsNeverExposedThroughAUrl`): dacă o intrare din baseline e reparată (chiar
     * primește tiebreaker-ul), garda principală de mai sus nu mai are cum s-o vadă — a
     * ieșit din lista de rezultate ale detectorului. Fără acest test, baseline-ul ar
     * „proteja" tăcut un fișier care n-are nevoie de protecție, iar o regresie VIITOARE pe
     * același loc (cineva scoate tiebreaker-ul din greșeală) ar trece nedetectată.
     */
    public function test_baseline_of_created_at_ordering_without_id_only_shrinks(): void
    {
        foreach ($this->createdAtOrderingBaseline() as $entry) {
            $path = __DIR__.'/../../'.$entry['file'];

            $this->assertFileExists($path, "Fișier din baseline care nu mai există: {$entry['file']} — scoate intrarea.");

            $hits = $this->createdAtOrderingHitsIn(file_get_contents($path));
            $stillOffending = false;

            foreach ($hits as $hit) {
                if ($hit['line'] === $entry['line']) {
                    $stillOffending = true;
                }
            }

            $this->assertTrue(
                $stillOffending,
                "Baseline {$entry['file']}:{$entry['line']} nu mai e ofensator — scoate intrarea din ".
                'createdAtOrderingBaseline(), nu o lăsa să protejeze tăcut alt cod adăugat mai târziu pe aceeași linie.'
            );
        }
    }

    /**
     * Auto-verificare a detectorului: fără acest test, o eroare în `createdAtOrderingHitsIn()`
     * care l-ar face să nu găsească NIMIC ar lăsa garda de mai sus verde din greșeală —
     * exact tiparul „gate care dovedește simetria, nu conținutul" deja găsit o dată în
     * lotul I18N (`i18n:coverage`, vezi docblock-ul testului de mesaje mai sus).
     */
    public function test_the_created_at_ordering_detector_flags_offenders_and_passes_safe_chains(): void
    {
        $offending = <<<'PHP'
            <?php

            class Example
            {
                public function bad()
                {
                    return Invoice::query()->where('order_id', $id)->latest('created_at')->first();
                }

                public function alsoBad()
                {
                    return Invoice::query()->latestOfMany(['created_at']);
                }

                public function badOnChangedAt()
                {
                    return DealStageEvent::query()->where('deal_id', $id)->latest('changed_at')->first();
                }

                public function badOnAnyOtherAtColumn()
                {
                    return Payment::query()->latest('paid_at')->first();
                }
            }
            PHP;

        $safe = <<<'PHP'
            <?php

            class Example
            {
                public function good()
                {
                    return Invoice::query()
                        ->where('order_id', $id)
                        ->latest('created_at')
                        ->orderByDesc('id')
                        ->first();
                }

                public function alsoGood()
                {
                    return $this->hasOne(Invoice::class)->latestOfMany(['created_at', 'id']);
                }

                public function goodOnChangedAt()
                {
                    return DealStageEvent::query()->orderByDesc('changed_at')->orderByDesc('id')->first();
                }

                public function unrelatedColumnIsNotInScope()
                {
                    return Invoice::query()->latest('number')->first();
                }
            }
            PHP;

        $offendingHits = $this->createdAtOrderingHitsIn($offending);
        // Se verifică CE a semnalat, nu doar câte: la o simplă numărătoare, un hit pierdut și
        // altul dublat se anulează reciproc și testul rămâne verde. Numerele de linie ar fi
        // fost și mai precise, dar se schimbă la orice editare a fixture-ului de mai sus.
        $descrieri = array_column($offendingHits, 'description');

        foreach (['pe `created_at`', 'fără `id` printre coloanele de ordonare', 'pe `changed_at`', 'pe `paid_at`'] as $asteptat) {
            $this->assertSame(
                1,
                count(array_filter($descrieri, fn (string $d): bool => str_contains($d, $asteptat))),
                sprintf('Detectorul trebuia să semnaleze exact o dată „%s"; a semnalat: %s', $asteptat, implode(' | ', $descrieri))
            );
        }

        $this->assertCount(4, $offendingHits);
        $this->assertSame($lines = array_column($offendingHits, 'line'), array_unique($lines), 'Două hituri pe aceeași linie.');

        $this->assertSame([], $this->createdAtOrderingHitsIn($safe), 'Detectorul a semnalat fals-pozitiv un lanț cu tiebreaker pe id deja prezent (sau o coloană din afara domeniului regulii).');
    }

    /**
     * Debit cunoscut, verificat individual la 2026-09-23 (fișier:linie, nu doar fișier —
     * o intrare nouă pe alt rând din același fișier NU e acoperită tăcut). Toate sunt ÎN
     * AFARA feliei acestui task (Products/Accounts/Contacts/Orders/Imports/timeline-ul de
     * cont) — nu se repară aici. Lista poate doar SCĂDEA (vezi testul simetric de mai sus).
     *
     * @return list<array{file: string, line: int}>
     */
    private function createdAtOrderingBaseline(): array
    {
        return [
            // `Accounts/Show.tsx`, secțiunea „Deals" a unui cont — listă scurtă (limit 20),
            // UI necritic; o coadă nedeterministă la egalitate de secundă schimbă cel mult
            // ordinea a două carduri adiacente, nu vizibilitatea datelor.
            ['file' => 'app/Http/Controllers/Web/Accounts/AccountController.php', 'line' => 144],
            // `Contacts/Show.tsx`, secțiunea „Deals" a unui contact — același profil de risc
            // ca rândul de mai sus (listă scurtă, afișare, nu decizie de business).
            ['file' => 'app/Http/Controllers/Web/Contacts/ContactController.php', 'line' => 111],
            // `Imports/Index.tsx` — cele mai recente 50 importuri, limită mică, doar afișare.
            ['file' => 'app/Http/Controllers/Web/Imports/ImportController.php', 'line' => 53],
            // `AccountActivityTimeline::build()` — blocul „Deals" al cronologiei unui cont
            // (limit LIMIT, doar afișare). Al doilea bloc din același fișier, „ActivityLog",
            // ARE deja tiebreaker pe `id` (linia ~112) — de aceea baseline-ul ține doar linia
            // exactă a blocului nereparat, nu tot fișierul.
            ['file' => 'app/Support/Accounts/AccountActivityTimeline.php', 'line' => 49],
        ];
    }

    /**
     * @param  list<array{file: string, line: int}>  $baseline
     */
    private function isBaselinedCreatedAtOrdering(string $relativeFile, int $line): bool
    {
        foreach ($this->createdAtOrderingBaseline() as $entry) {
            if ($entry['file'] === $relativeFile && $entry['line'] === $line) {
                return true;
            }
        }

        return false;
    }

    /**
     * Parcurge sursa cu `nikic/php-parser` și întoarce fiecare ordonare pe `created_at`
     * (sau `latestOfMany`/`oldestOfMany` fără `id`) care NU are un tiebreaker pe `id` în
     * același lanț fluent. Vezi docblock-ul testului de mai sus pentru regulile exacte.
     *
     * @return list<array{line: int, description: string}>
     */
    private function createdAtOrderingHitsIn(string $code): array
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

        $collector = new class extends NodeVisitorAbstract
        {
            /** @var list<array{line: int, description: string}> */
            public array $found = [];

            public function enterNode(Node $node)
            {
                if (! $node instanceof Node\Expr\MethodCall || ! $node->name instanceof Node\Identifier) {
                    return null;
                }

                $method = $node->name->toString();

                if (in_array($method, ['latestOfMany', 'oldestOfMany'], true)) {
                    if (! $this->orderOfManyHasIdTiebreaker($node)) {
                        $this->found[] = [
                            'line' => $node->getStartLine(),
                            'description' => sprintf('->%s(...) fără `id` printre coloanele de ordonare', $method),
                        ];
                    }

                    return null;
                }

                if (! in_array($method, ['latest', 'oldest', 'orderBy', 'orderByDesc'], true)) {
                    return null;
                }

                $column = $this->orderedColumn($node, $method);

                // Domeniul e PROPRIETATEA, nu istoricul bug-urilor: `$table->timestamp('…')`
                // creează pe Postgres `timestamp(0)`, deci ORICE coloană `*_at` din migrațiile
                // proiectului se trunchiază la secundă și poate produce egalități. Prima
                // versiune acoperea doar `created_at`, a doua a adăugat `changed_at` fiindcă
                // acolo s-a văzut un eșec — dar a aștepta un eșec per coloană înseamnă a aștepta
                // un bug per coloană.
                if ($column === null || preg_match('/(^|\.)[a-z_]+_at$/', $column) !== 1) {
                    return null;
                }

                if (! $this->chainHasIdTiebreaker($node)) {
                    $this->found[] = [
                        'line' => $node->getStartLine(),
                        'description' => sprintf(
                            '->%s(%s) pe `%s` fără id în același lanț',
                            $method,
                            isset($node->args[0]) ? sprintf("'%s', ...", $column) : '',
                            $column,
                        ),
                    ];
                }

                return null;
            }

            /**
             * Coloana pe care ordonează apelul, dacă e determinabilă static din sursă —
             * `null` dacă argumentul e dinamic (variabilă, concatenare) sau lipsă pentru o
             * metodă care nu are implicit `created_at` (`orderBy`/`orderByDesc`).
             */
            private function orderedColumn(Node\Expr\MethodCall $node, string $method): ?string
            {
                if (in_array($method, ['latest', 'oldest'], true) && ! isset($node->args[0])) {
                    // Regula din brief: „latest()/oldest() fără argument = created_at".
                    return 'created_at';
                }

                return $this->stringArg($node, 0);
            }

            private function stringArg(Node\Expr\MethodCall $node, int $index): ?string
            {
                if (! isset($node->args[$index]) || ! $node->args[$index] instanceof Node\Arg) {
                    return null;
                }

                $value = $node->args[$index]->value;

                return $value instanceof Node\Scalar\String_ ? $value->value : null;
            }

            private function orderOfManyHasIdTiebreaker(Node\Expr\MethodCall $node): bool
            {
                if (! isset($node->args[0]) || ! $node->args[0] instanceof Node\Arg) {
                    // Fără argument, `latestOfMany()`/`oldestOfMany()` ordonează pe coloana
                    // implicită a modelului (niciodată `id`) — ofensator prin definiție.
                    return false;
                }

                $value = $node->args[0]->value;

                if ($value instanceof Node\Scalar\String_) {
                    return $value->value === 'id';
                }

                if ($value instanceof Node\Expr\Array_) {
                    foreach ($value->items as $item) {
                        if ($item !== null && $item->value instanceof Node\Scalar\String_ && $item->value->value === 'id') {
                            return true;
                        }
                    }
                }

                return false;
            }

            /**
             * Urcă la verigă cea mai exterioară a lanțului fluent care conține `$node`
             * (cât timp `$node` e chiar receptorul — `->var` — apelului părinte), apoi
             * coboară prin `->var` al fiecărui `MethodCall`, verificând fiecare verigă.
             * Se oprește la primul `StaticCall`/`Variable`/altceva care nu mai e un
             * `MethodCall` — rădăcina lanțului, în afara căreia „același lanț" nu mai are
             * sens (ex. o interogare salvată în altă variabilă, într-o altă instrucțiune).
             */
            private function chainHasIdTiebreaker(Node\Expr\MethodCall $node): bool
            {
                $top = $node;

                while (($parent = $top->getAttribute('parent')) instanceof Node\Expr\MethodCall && $parent->var === $top) {
                    $top = $parent;
                }

                $current = $top;

                while ($current instanceof Node\Expr\MethodCall) {
                    if ($this->isIdTiebreaker($current)) {
                        return true;
                    }

                    $current = $current->var;
                }

                return false;
            }

            private function isIdTiebreaker(Node\Expr\MethodCall $node): bool
            {
                if (! $node->name instanceof Node\Identifier) {
                    return false;
                }

                $method = $node->name->toString();

                if (! in_array($method, ['orderBy', 'orderByDesc', 'latest', 'oldest'], true)) {
                    return false;
                }

                return $this->stringArg($node, 0) === 'id';
            }
        };

        $traverser = new NodeTraverser;
        $traverser->addVisitor(new ParentConnectingVisitor);
        $traverser->addVisitor($collector);
        $traverser->traverse($ast);

        return $collector->found;
    }

    /**
     * I18N-03, recidivă catalogată în audit (2026-09-23, §3.10) — coloana `error_message`
     * (`bulk_operations`, `shipments`, `report_runs`, `data_export_requests`, `webhook_events`)
     * e randată prin `App\Support\JobErrorMessage::render()`, care traduce o CHEIE codificată
     * (`JobErrorMessage::encode()`) abia la citire, în locale-ul cererii care randează ecranul —
     * vezi docblock-ul clasei pentru motivul complet: o valoare tradusă la SCRIERE ar îngheța
     * limba WORKERULUI, nu a cererii care randează mai târziu. Un `$e->getMessage()` sau un
     * literal englez scris direct pe coloană ocolește complet traducerea — `render()` îl trece
     * NESCHIMBAT (calea „tolerantă", păstrată pentru rânduri vechi/text extern de furnizor),
     * deci ajunge pe ecran exact așa cum a fost scris, indiferent de limba cererii care-l
     * afișează.
     *
     * **De ce AST, nu regex** — același argument ca la
     * `test_user_facing_message_sinks_never_carry_a_raw_string_literal()` de mai sus:
     * `update([...])`, `create([...])` și `forceFill([...])` sunt toate literale de array PHP,
     * iar cheia `'error_message'` poate sta lângă alte chei, pe linii diferite, în interiorul
     * unui ternar sau al unui `??` — exact structura pe care un parser real o vede fără
     * ambiguitate și un regex o ghicește.
     *
     * **Ce prinde, exact** (vezi `errorMessageSinkHitsIn()` mai jos pentru detector):
     *   - orice `ArrayItem` cu cheia literală `'error_message'` (acoperă `update()`/
     *     `create()`/`forceFill()`, indiferent de apelant), a cărui valoare e un literal de
     *     șir sau un apel `->getMessage()`;
     *   - orice atribuire directă `$x->error_message = <valoare>`, cu aceeași formă;
     *   - FIECARE RAMURĂ a unui ternar (`$a ? $b : $c`, inclusiv forma elvis `$a ?: $b`) sau a
     *     unui `??` — un singur braț ofensator e suficient, chiar dacă celălalt e deja corect
     *     (ex. `GenerateShippingLabelJob::markFailed()`: ramura raportată de furnizor rămâne
     *     text brut deliberat, cea generică e deja codificată — AMBELE brațe sunt inspectate).
     *
     * **Ce NU prinde, deliberat**:
     *   - `null` (un shipment/export/raport fără eroare) — valoare EXPLICIT permisă, nu
     *     „scăpată";
     *   - o proprietate/variabilă oarecare (`$export->error_message`, pass-through al unei
     *     valori deja codificate — `FinalizeDataExportJob::markFailed()`) sau un apel static
     *     (`JobErrorMessage::encode(...)`) — garda nu poate ști static dacă întoarce text
     *     corect, dar niciunul din cele două NU e un literal/`getMessage()` direct, singurul
     *     semnal cerut aici;
     *   - lista de nume de coloane a unui atribut `#[Fillable([...])]` — acolo
     *     `'error_message'` e o VALOARE de listă, fără cheie, nu cheia unui `ArrayItem`.
     *
     * **Excepții** — `errorMessageExceptions()` mai jos, pe FIȘIER întreg (nu pe text exact,
     * ca la `sinkExceptions()`): un ecran de operare intern poate avea mai multe scrieri
     * brute, toate acoperite de ACEEAȘI motivare.
     *
     * **Ce a găsit la prima rulare (2026-09-23)**, toate reparate în același lot, fără nicio
     * intrare în `errorMessageExceptions()`: catch-all-urile din `PlanBulkOperationJob`,
     * `ExportListJob` și `GenerateReportJob` (acum `job_errors.*.unexpected`), explicația în
     * engleză din `StripeWebhookController::ignore()` (acum `job_errors.webhook.*`) și motivul
     * transportatorului din `GenerateShippingLabelJob` (acum parametru al cheii-cadru
     * `job_errors.shipment.carrier_rejected`).
     */
    public function test_the_error_message_column_never_stores_a_raw_string_or_getmessage_result(): void
    {
        $offenders = [];

        foreach ($this->phpFilesIn(__DIR__.'/../../app') as $file) {
            $relative = $this->relative($file);

            if ($this->isExceptedErrorMessageFile($relative)) {
                continue;
            }

            foreach ($this->errorMessageSinkHitsIn(file_get_contents($file->getPathname())) as $hit) {
                $offenders[] = sprintf('%s:%d  %s', $relative, $hit['line'], $hit['text']);
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "`error_message` a primit text brut în loc de o cheie JobErrorMessage::encode() (I18N-03).\n"
            ."Randarea (JobErrorMessage::render()) traduce doar chei codificate — orice altceva\n"
            ."trece neschimbat, în limba în care a fost scris de worker, nu a cererii care-l\n"
            .'afișează. Fiecare linie de mai jos dă fișierul, linia și forma exactă găsită:'."\n"
            .implode("\n", $offenders),
        );
    }

    /**
     * Singura excepție documentată azi — orice intrare nouă are nevoie de motivare proprie,
     * verificată, nu de o extindere tăcută a asteia.
     *
     * @return list<array{file: string, reason: string}>
     */
    private function errorMessageExceptions(): array
    {
        return [
            [
                'file' => 'app/Jobs/Webhooks/ProcessStripeWebhookJob.php',
                'reason' => 'failed() scrie $e->getMessage() brut pe error_message la a treia '.
                    'reîncercare eșuată a unui webhook Stripe intern — ecran de operare EXCLUSIV '.
                    'Owner (WebhookHealthController, gardă billing.view + '.
                    'SingleOwnerDeployment::active(), verificat în docblock-ul controllerului), '.
                    'niciodată expus unui Viewer/membru obișnuit. Mesajul e aici pentru DEPANARE '.
                    '(SDK Stripe/framework), nu pentru un utilizator final care ar avea nevoie de '.
                    'traducere — și oricum n-are catalog de tradus, fiind text dinamic extern.',
            ],
        ];
    }

    private function isExceptedErrorMessageFile(string $relativeFile): bool
    {
        foreach ($this->errorMessageExceptions() as $exception) {
            if ($exception['file'] === $relativeFile) {
                return true;
            }
        }

        return false;
    }

    /**
     * Parcurge sursa cu `nikic/php-parser` și întoarce fiecare scriere „ofensatoare" pe
     * `error_message` (vezi docblock-ul testului de mai sus pentru forma exactă).
     *
     * @return list<array{line: int, text: string}>
     */
    private function errorMessageSinkHitsIn(string $code): array
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
            /** @var list<array{line: int, text: string}> */
            public array $found = [];

            /**
             * Aliasuri „ofensatoare" pe domeniu de funcție: `$msg = $e->getMessage();` urmat
             * de `'error_message' => $msg` în ACEEAȘI metodă/closure. Stivă, nu hartă globală:
             * o variabilă cu același nume din altă metodă nu are nicio legătură.
             *
             * @var list<array<string, string>>
             */
            private array $scopes = [[]];

            public function leaveNode(Node $node)
            {
                if ($this->opensScope($node)) {
                    array_pop($this->scopes);
                }

                return null;
            }

            public function enterNode(Node $node)
            {
                if ($this->opensScope($node)) {
                    $this->scopes[] = [];
                }

                // $alias = <valoare> — ținut minte cât timp valoarea e ofensatoare; o
                // reasignare sigură (ex. `JobErrorMessage::encode(...)`) îl șterge.
                if ($node instanceof Node\Expr\Assign
                    && $node->var instanceof Node\Expr\Variable
                    && is_string($node->var->name)) {
                    $description = $this->forbiddenDescription($node->expr);
                    $top = array_key_last($this->scopes);

                    if ($description !== null) {
                        $this->scopes[$top][$node->var->name] = $description;
                    } else {
                        unset($this->scopes[$top][$node->var->name]);
                    }
                }

                // ['error_message' => <valoare>] — acoperă update([...]), create([...]),
                // forceFill([...]), indiferent de metoda care primește array-ul: garda nu se
                // uită la NUMELE apelului, ci la orice literal de array cu cheia asta.
                if ($node instanceof Node\Expr\ArrayItem
                    && $node->key instanceof Node\Scalar\String_
                    && $node->key->value === 'error_message') {
                    $this->inspect($node->value, $node->getStartLine());
                }

                // $x->error_message = <valoare>
                if ($node instanceof Node\Expr\Assign
                    && $node->var instanceof Node\Expr\PropertyFetch
                    && $node->var->name instanceof Node\Identifier
                    && $node->var->name->toString() === 'error_message') {
                    $this->inspect($node->expr, $node->getStartLine());
                }

                return null;
            }

            private function inspect(Node $value, int $line): void
            {
                $description = $this->forbiddenDescription($value);

                if ($description !== null) {
                    $this->found[] = ['line' => $line, 'text' => $description];
                }
            }

            /**
             * Descrierea valorii interzise, sau `null` dacă valoarea e permisă (`null`, o
             * proprietate/apel oarecare — presupus deja codificat — sau
             * `JobErrorMessage::encode(...)`). Ternarele (inclusiv forma elvis) și `??` se
             * evaluează RECURSIV pe fiecare ramură care poate deveni valoarea finală — un
             * singur braț ofensator e suficient.
             */
            private function forbiddenDescription(Node $value): ?string
            {
                if ($value instanceof Node\Expr\MethodCall
                    && $value->name instanceof Node\Identifier
                    && $value->name->toString() === 'getMessage') {
                    return '->getMessage()';
                }

                if ($value instanceof Node\Scalar\String_) {
                    return sprintf("literal '%s'", $value->value);
                }

                // Audit 2026-09-23: text lipit din bucăți e tot text brut, oricare ar fi bucățile.
                if ($value instanceof Node\Scalar\InterpolatedString || $value instanceof Node\Expr\BinaryOp\Concat) {
                    return 'text concatenat/interpolat';
                }

                if ($value instanceof Node\Expr\Variable && is_string($value->name)) {
                    $aliases = $this->scopes[array_key_last($this->scopes)];

                    return isset($aliases[$value->name])
                        ? sprintf('$%s (= %s)', $value->name, $aliases[$value->name])
                        : null;
                }

                if ($value instanceof Node\Expr\Ternary) {
                    // Elvis (`$a ?: $b`): `if` e null, ramura „adevărată" e chiar `cond`.
                    $truthy = $value->if ?? $value->cond;

                    return $this->forbiddenDescription($truthy) ?? $this->forbiddenDescription($value->else);
                }

                if ($value instanceof Node\Expr\BinaryOp\Coalesce) {
                    return $this->forbiddenDescription($value->left) ?? $this->forbiddenDescription($value->right);
                }

                // `__()`/`trans()` la SCRIERE îngheață limba workerului în coloană — exact bug-ul
                // I18N-03; `sprintf()` produce text brut.
                if ($value instanceof Node\Expr\FuncCall
                    && $value->name instanceof Node\Name
                    && in_array(strtolower($value->name->toString()), ['__', 'trans', 'trans_choice', 'sprintf', 'vsprintf'], true)) {
                    return $value->name->toString().'() — text gata format la scriere';
                }

                // `JobErrorMessage::encode(...)` e forma sancționată, cu tot cu parametrii ei
                // (motivul brut al unui furnizor intră ca parametru, deliberat).
                if ($value instanceof Node\Expr\StaticCall
                    && $value->class instanceof Node\Name
                    && str_ends_with($value->class->toString(), 'JobErrorMessage')
                    && $value->name instanceof Node\Identifier
                    && $value->name->toString() === 'encode') {
                    return null;
                }

                // Orice alt apel care ÎNVELEȘTE un mesaj brut (`Str::limit($e->getMessage())`,
                // `mb_substr($msg, …)`) — argumentele-cheie literale ale unui helper nu contează.
                if ($value instanceof Node\Expr\CallLike && ! $value->isFirstClassCallable()) {
                    foreach ($value->getArgs() as $argument) {
                        $inner = $this->rawMessageInside($argument->value);

                        if ($inner !== null) {
                            return sprintf('apel care învelește %s', $inner);
                        }
                    }
                }

                return null;
            }

            /** Un `->getMessage()` sau un alias ofensator oriunde în subarbore. */
            private function rawMessageInside(Node $node): ?string
            {
                $aliases = $this->scopes[array_key_last($this->scopes)];

                $hit = (new NodeFinder)->findFirst($node, fn (Node $candidate): bool => ($candidate instanceof Node\Expr\MethodCall
                        && $candidate->name instanceof Node\Identifier
                        && $candidate->name->toString() === 'getMessage')
                    || ($candidate instanceof Node\Expr\Variable
                        && is_string($candidate->name)
                        && isset($aliases[$candidate->name])));

                if ($hit === null) {
                    return null;
                }

                return $hit instanceof Node\Expr\Variable ? '$'.$hit->name : '->getMessage()';
            }

            private function opensScope(Node $node): bool
            {
                return $node instanceof Node\Stmt\ClassMethod
                    || $node instanceof Node\Stmt\Function_
                    || $node instanceof Node\Expr\Closure
                    || $node instanceof Node\Expr\ArrowFunction;
            }
        };

        $traverser = new NodeTraverser;
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        return $visitor->found;
    }

    /**
     * Auto-verificare a detectorului de mai sus — fără ea, o eroare în
     * `errorMessageSinkHitsIn()` care l-ar face să nu găsească NIMIC ar lăsa garda principală
     * verde din greșeală, exact tiparul deja documentat la
     * `test_the_created_at_ordering_detector_flags_offenders_and_passes_safe_chains()` mai sus.
     */
    public function test_the_error_message_sink_detector_flags_offenders_and_passes_safe_values(): void
    {
        $offending = <<<'PHP'
            <?php

            class Example
            {
                public function directAssignment(): void
                {
                    $shipment->error_message = $e->getMessage();
                }

                public function literalInUpdateCall(): void
                {
                    $shipment->update([
                        'status' => 'label_failed',
                        'error_message' => 'Something went wrong.',
                    ]);
                }

                public function oneBadBranchOfATernary(): void
                {
                    $shipment->update([
                        'error_message' => $isCarrierReported
                            ? $e->getMessage()
                            : JobErrorMessage::encode('job_errors.x'),
                    ]);
                }

                public function oneBadSideOfACoalesce(): void
                {
                    $shipment->update(['error_message' => $e->getMessage() ?? null]);
                }

                public function interpolatedText(): void
                {
                    $shipment->update(['error_message' => "Failed: {$e->getMessage()}"]);
                }

                public function concatenatedText(): void
                {
                    $shipment->update(['error_message' => 'Failed for '.$reason]);
                }

                public function wrappedRawMessage(): void
                {
                    $shipment->update(['error_message' => Str::limit($e->getMessage(), 200)]);
                }

                public function translatedAtWriteTime(): void
                {
                    $shipment->update(['error_message' => __('job_errors.x')]);
                }

                public function aliasedRawMessage(): void
                {
                    $message = $e->getMessage();
                    $shipment->update(['error_message' => $message]);
                }
            }
            PHP;

        $safe = <<<'PHP'
            <?php

            class Example
            {
                public function encodedKeyIsFine(): void
                {
                    $shipment->update([
                        'error_message' => JobErrorMessage::encode('job_errors.x'),
                    ]);
                }

                public function nullIsFine(): void
                {
                    $shipment->update(['error_message' => null]);
                }

                public function propertyPassthroughIsFine(): void
                {
                    $export->update([
                        'error_message' => $export->error_message ?? JobErrorMessage::encode('job_errors.y'),
                    ]);
                }

                public function unrelatedKeyIsOutOfScope(): void
                {
                    $shipment->update(['message' => $e->getMessage()]);
                }

                public function rawReasonAsAnEncodedParameterIsFine(): void
                {
                    $shipment->update([
                        'error_message' => JobErrorMessage::encode('job_errors.shipment.carrier_rejected', ['reason' => $e->getMessage()]),
                    ]);
                }

                public function reassignedAliasIsFine(): void
                {
                    $message = $e->getMessage();
                    $message = JobErrorMessage::encode('job_errors.x');
                    $shipment->update(['error_message' => $message]);
                }

                public function rawMessageOnlyLogged(): void
                {
                    $message = $e->getMessage();
                    Log::warning($message);
                }

                public function sameNameInAnotherMethodIsUnrelated(): void
                {
                    $shipment->update(['error_message' => $message]);
                }

                public function helperWithAKeyArgumentIsFine(): void
                {
                    $shipment->update(['error_message' => $this->encodedFailure('job_errors.x')]);
                }
            }
            PHP;

        $offendingHits = $this->errorMessageSinkHitsIn($offending);
        $this->assertCount(9, $offendingHits, 'Detectorul n-a semnalat toate cele nouă forme ofensatoare (atribuire directă, literal, ternar, coalesce, interpolare, concatenare, înveliș, __() la scriere, alias) — garda ar fi verde din greșeală.');

        $this->assertSame([], $this->errorMessageSinkHitsIn($safe), 'Detectorul a semnalat fals-pozitiv o valoare permisă (cheie codificată, cu sau fără parametru brut, null, pass-through, alias reasignat sau din altă metodă, helper cu argument-cheie) sau a ieșit din sink-ul `error_message` propriu-zis.');
    }

    /**
     * I18N-09 (audit 2026-09-23, §3.10) — `throw new X('literal englez')` ocolește complet
     * `test_user_facing_message_sinks_never_carry_a_raw_string_literal()` de mai sus: acela
     * scanează cinci SINK-uri de mesaj (`errors()->add`, `withMessages`, `$fail`, `abort`,
     * `messages()/attributes()`), nu constructorul unei excepții. Un
     * `grep -rEn "throw new [A-Za-z\\\\]*Exception\(['\"]" app` (verificat manual, ~19-27
     * rezultate, în afara acestui fișier) arată că marea majoritate sunt
     * `RuntimeException`/`InvalidArgumentException` — invarianți INTERNI (configurare
     * coruptă, apelant care ocolește validarea deja făcută de un `FormRequest` sau de o
     * constrângere de rută), care nu ajung niciodată la utilizator: necapturate, cad pe
     * pagina 500 GENERICĂ a handler-ului Laravel, care ascunde `getMessage()` în producție.
     *
     * Exact ACEEAȘI regulă a handler-ului arată și DE CE clasele din familia `HttpException`
     * sunt altfel — verificat în
     * `vendor/laravel/framework/.../Foundation/Exceptions/Handler.php::convertExceptionToArray()`:
     * `config('app.debug') ? [...] : ['message' => $this->isHttpException($e) ?
     * $e->getMessage() : 'Server Error']` — `isHttpException($e)` e SINGURA ramură care scapă
     * de `'Server Error'`, chiar cu `app.debug=false`. Un `HttpException`/
     * `AuthorizationException`/`ValidationException` (sau o subclasă) e conceput de framework
     * ca fiind SIGUR de arătat, spre deosebire de o excepție oarecare.
     *
     * Descoperit concret, nu doar teoretic, în `App\Support\Exports\ListExport::respond()`:
     * `throw new HttpException(422, 'This list cannot be exported as a zip archive...')` ieșea
     * neschimbat într-un răspuns JSON chiar cu `app.debug=false` — reparat în același lot cu
     * garda de față (`__('exports.errors.zip_not_supported')`, chei noi simetrice în
     * `lang/en/exports.php`/`lang/fr/exports.php`).
     *
     * **Ce prinde, exact** (vezi `newExceptionLiteralHitsIn()` mai jos pentru detector):
     * `new X(<argument>)` pentru orice `X` care E sau EXTINDE (verificat prin reflecție —
     * `is_subclass_of()`, nu doar comparație de nume, ca să prindă și o viitoare subclasă
     * proprie a proiectului) una din `userVisibleExceptionBaseClasses()` mai jos, dacă VREUNUL
     * din argumentele apelului e un literal de șir (sau o interpolare/concatenare care conține
     * unul). `X` e rezolvat la numele complet calificat prin
     * `PhpParser\NodeVisitor\NameResolver` (rezolvatorul din `nikic/php-parser`, pe bază de
     * `use`-uri și namespace curent), nu comparat cu textul brut — deci prinde și forma scrisă
     * cu namespace complet direct la fața locului (`new \Illuminate\Auth\Access\
     * AuthorizationException(...)`).
     *
     * **Ce NU prinde, deliberat**:
     *   - `RuntimeException`/`InvalidArgumentException`/orice clasă din afara listei — acelea
     *     sunt garda VECHE (vezi mai sus în acest docblock), nu recidiva de față;
     *   - un mesaj trecut prin `__()`/`trans()`/`trans_choice()` — valoarea argumentului e un
     *     APEL de funcție, nu un literal, exact distincția cerută de FR-I18N-04;
     *   - un array (de headere sau orice altceva) ca argument — garda inspectează valoarea
     *     FIECĂRUI argument individual, nu recursiv în interiorul array-urilor literale, deci
     *     `['Retry-After' => '60']` ca argument propriu e „un array", nu „un literal".
     *
     * **Consecvență cu `sinkExceptions()`** — literalul 429 din `PasswordResetLinkController`
     * (`abort(429, '...')`) rămâne acceptat ACOLO, pe garda veche: e un `abort()`, nu un
     * `new X(...)`, deci nici măcar nu intră în scanarea de față. Nicio politică dublă pentru
     * același literal.
     */
    public function test_new_user_visible_exceptions_never_carry_a_raw_string_literal(): void
    {
        $offenders = [];

        foreach ($this->phpFilesIn(__DIR__.'/../../app') as $file) {
            $relative = $this->relative($file);

            foreach ($this->newExceptionLiteralHitsIn(file_get_contents($file->getPathname())) as $hit) {
                $offenders[] = sprintf('%s:%d  new %s(%s)', $relative, $hit['line'], $hit['class'], $hit['text']);
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "Literal englez găsit direct într-un constructor de excepție VIZIBILĂ utilizatorului\n"
            ."(I18N-09) — HttpException/AuthorizationException/ValidationException (sau o\n"
            ."subclasă) randează mesajul chiar și cu app.debug=false. Forma corectă e mereu\n"
            ."__()/trans() — vezi domeniul potrivit din lang/{en,fr}/. Fiecare linie de mai jos\n"
            .'dă fișierul, linia, clasa și argumentul:'."\n"
            .implode("\n", $offenders),
        );
    }

    /**
     * @return list<class-string<\Throwable>>
     */
    private function userVisibleExceptionBaseClasses(): array
    {
        return [
            HttpException::class,
            AuthorizationException::class,
            ValidationException::class,
        ];
    }

    /**
     * Parcurge sursa cu `nikic/php-parser` + `NameResolver` și întoarce fiecare `new X(...)`
     * al cărui `X` e vizibil utilizatorului (vezi docblock-ul testului de mai sus) și care
     * primește cel puțin un argument literal.
     *
     * @return list<array{line: int, class: string, text: string}>
     */
    private function newExceptionLiteralHitsIn(string $code): array
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

        $collector = new class($this->userVisibleExceptionBaseClasses()) extends NodeVisitorAbstract
        {
            /** @var list<array{line: int, class: string, text: string}> */
            public array $found = [];

            /**
             * @param  list<class-string>  $baseClasses
             */
            public function __construct(private readonly array $baseClasses) {}

            public function enterNode(Node $node)
            {
                if (! $node instanceof Node\Expr\New_ || ! $node->class instanceof Node\Name) {
                    return null;
                }

                $className = ltrim($node->class->toString(), '\\');

                if (! $this->isUserVisible($className)) {
                    return null;
                }

                foreach ($node->args as $arg) {
                    if (! $arg instanceof Node\Arg) {
                        continue;
                    }

                    $text = $this->literalTextIn($arg->value);

                    if ($text !== null) {
                        $this->found[] = ['line' => $node->getStartLine(), 'class' => $className, 'text' => $text];
                    }
                }

                return null;
            }

            /**
             * `$class` e deja numele COMPLET calificat (`NameResolver` a rulat înaintea
             * acestui vizitator) — comparat direct cu bazele, apoi verificat prin reflecție
             * pentru orice subclasă (proprie proiectului sau a framework-ului).
             */
            private function isUserVisible(string $class): bool
            {
                foreach ($this->baseClasses as $base) {
                    $base = ltrim($base, '\\');

                    if ($class === $base) {
                        return true;
                    }

                    // `class_exists()`/`interface_exists()` declanșează autoload-ul — sigur de
                    // apelat aici, fără bootstrap de aplicație: excepțiile verificate n-au
                    // dependențe de container la ÎNCĂRCAREA clasei, doar (eventual) la
                    // instanțiere.
                    if ((class_exists($class) || interface_exists($class)) && is_subclass_of($class, $base)) {
                        return true;
                    }
                }

                return false;
            }

            private function literalTextIn(Node $value): ?string
            {
                if ($value instanceof Node\Scalar\String_) {
                    return sprintf("'%s'", $value->value);
                }

                if ($value instanceof Node\Scalar\InterpolatedString || $value instanceof Node\Expr\BinaryOp\Concat) {
                    return '<interpolat> '.$this->flatten($value);
                }

                // Audit 2026-09-23 — o singură ramură literală e suficientă ca textul brut să
                // ajungă la utilizator; `sprintf('literal %s', …)` e tot un literal.
                if ($value instanceof Node\Expr\Ternary) {
                    return $this->literalTextIn($value->if ?? $value->cond) ?? $this->literalTextIn($value->else);
                }

                if ($value instanceof Node\Expr\BinaryOp\Coalesce) {
                    return $this->literalTextIn($value->left) ?? $this->literalTextIn($value->right);
                }

                if ($value instanceof Node\Expr\FuncCall
                    && $value->name instanceof Node\Name
                    && in_array(strtolower($value->name->toString()), ['sprintf', 'vsprintf'], true)
                    && isset($value->args[0])
                    && $value->args[0] instanceof Node\Arg
                    && $value->args[0]->value instanceof Node\Scalar\String_) {
                    return '<sprintf> '.$value->args[0]->value->value;
                }

                return null;
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
        $traverser->addVisitor(new NameResolver);
        $traverser->addVisitor($collector);
        $traverser->traverse($ast);

        return $collector->found;
    }

    /**
     * Auto-verificare a detectorului de mai sus — același motiv ca la celelalte două gărzi pe
     * bază de AST din acest fișier.
     */
    public function test_the_new_exception_literal_detector_flags_offenders_and_passes_safe_calls(): void
    {
        $offending = <<<'PHP'
            <?php

            use Symfony\Component\HttpKernel\Exception\HttpException;
            use Illuminate\Auth\Access\AuthorizationException;

            class Example
            {
                public function directLiteral(): void
                {
                    throw new HttpException(422, 'Raw text shown to the user.');
                }

                public function fullyQualifiedFormIsStillCaught(): void
                {
                    throw new \Illuminate\Auth\Access\AuthorizationException('Raw text.');
                }

                public function interpolatedIsStillALiteral(): void
                {
                    throw new HttpException(422, "Unknown format {$format}.");
                }

                public function oneLiteralBranchOfATernary(): void
                {
                    throw new HttpException(422, $known ? __('exports.errors.unknown_format') : 'Unknown format.');
                }

                public function sprintfWithALiteralTemplate(): void
                {
                    throw new HttpException(422, sprintf('Unknown format %s.', $format));
                }
            }
            PHP;

        $safe = <<<'PHP'
            <?php

            use Symfony\Component\HttpKernel\Exception\HttpException;
            use RuntimeException;

            class Example
            {
                public function translatedMessageIsFine(): void
                {
                    throw new HttpException(422, __('exports.errors.unknown_format', ['format' => $raw]));
                }

                public function unrelatedClassIsOutOfScope(): void
                {
                    throw new RuntimeException('Internal invariant, never shown to a user.');
                }

                public function headersArrayArgumentIsNotAScalarLiteral(): void
                {
                    throw new HttpException(429, __('rules.too_many_requests'), null, ['Retry-After' => '60']);
                }
            }
            PHP;

        $offendingHits = $this->newExceptionLiteralHitsIn($offending);
        $this->assertCount(5, $offendingHits, 'Detectorul n-a semnalat toate cele cinci forme ofensatoare (literal direct, formă complet calificată, interpolare, ramură literală de ternar, sprintf cu șablon literal) — garda ar fi verde din greșeală.');

        $this->assertSame([], $this->newExceptionLiteralHitsIn($safe), 'Detectorul a semnalat fals-pozitiv un mesaj tradus (__()), o clasă din afara familiei vizibile, sau un array de headere ca argument.');
    }
}
