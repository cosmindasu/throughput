<?php

namespace Tests\Unit;

use App\Models\BulkOperationChunk;
use App\Models\IdempotencyKey;
use App\Models\SavedViewDefault;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;
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
     * **Ce NU verifică, deliberat**: o coloană diferită de `created_at` (`->latest('sent_at')`,
     * `->orderByDesc('changed_at')`) — acelea nu sunt clasa de bug documentată (alte coloane
     * pot avea altă precizie sau altă garanție de unicitate) și extinderea regulii la „orice
     * coloană" ar cere o listă albă mult mai mare, fără dovadă că bug-ul chiar există acolo.
     *
     * **Baseline, nu listă neagră**: fișierele din `createdAtOrderingBaseline()` sunt debit
     * cunoscut, verificat individual (linie exactă, nu doar fișier) — vezi
     * `test_baseline_of_created_at_ordering_without_id_only_shrinks()` imediat după, care
     * pică dacă o intrare e reparată și lăsată totuși în listă.
     */
    public function test_created_at_ordering_always_has_an_id_tiebreaker_in_the_same_chain(): void
    {
        $offenders = [];

        foreach ($this->phpFilesIn(__DIR__.'/../../app') as $file) {
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

                public function unrelatedColumnIsNotInScope()
                {
                    return Invoice::query()->latest('sent_at')->first();
                }
            }
            PHP;

        $offendingHits = $this->createdAtOrderingHitsIn($offending);
        $this->assertCount(2, $offendingHits, 'Detectorul n-a semnalat cazurile clar ofensatoare — garda ar fi verde din greșeală.');

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

                if ($column !== 'created_at') {
                    return null;
                }

                if (! $this->chainHasIdTiebreaker($node)) {
                    $this->found[] = [
                        'line' => $node->getStartLine(),
                        'description' => sprintf(
                            '->%s(%s) pe `created_at` fără id în același lanț',
                            $method,
                            isset($node->args[0]) ? "'created_at', ..." : '',
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
}
