<?php

namespace Tests\Feature\I18n;

use App\Models\Invoice;
use App\Services\Tenancy\TenantContext;
use App\Support\Permissions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Validator as ValidatorFacade;
use Illuminate\Support\Str;
use ReflectionClass;
use Tests\Concerns\ScansPhpSource;
use Tests\TestCase;

/**
 * I18N-01 (P1) — `lang/{en,fr}/validation.php` publica `'attributes' => []` gol în ambele
 * limbi (vezi docblock-ul acelui fișier pentru istoricul deciziei, acum depășită): fără
 * intrări acolo, orice eroare de validare pe un câmp cu nume compus randa numele englez,
 * humanizat, PE O INTERFAȚĂ ALTFEL COMPLET FRANCEZĂ — „Le champ credit terms est
 * obligatoire.", nu „Le champ conditions de crédit est obligatoire.".
 *
 * Trei ziduri de apărare, în ordine crescândă de realism:
 *
 *  (a) un `Validator::make()` direct, fără HTTP, pe cele două exemple din task (un câmp
 *      compus și unul de-un-cuvânt) — cea mai rapidă probă că traducerea EXISTĂ;
 *  (b) GATE-UL care lipsea: reflecție peste TOATE `FormRequest`-urile din
 *      `app/Http/Requests/**`, care verifică — pentru fiecare cheie găsită în `rules()` —
 *      o intrare `validation.attributes` în AMBELE limbi. Fără acest test, o cheie nouă
 *      adăugată într-un `rules()` viitor n-are nicio gardă care s-o oblige în catalog:
 *      `php artisan i18n:coverage` verifică doar simetria EN↔FR a cataloagelor, niciodată
 *      că o cheie de `rules()` are pereche acolo — exact tiparul „gate care dovedește
 *      simetria, nu conținutul" deja documentat în `ArchitectureTest` pentru sink-urile de
 *      mesaj;
 *  (c) o eroare de validare REALĂ, prin lanțul HTTP complet (`SetLocale` din
 *      `users.locale`, ca în `ValidationMessageLocaleTest`/`MemberRefusalLocaleTest`), pe
 *      un câmp compus (`billing_address.postal_code`) — proba că traducerea chiar ajunge
 *      pe ecran, nu doar în catalog.
 *
 * **De ce instanțiere prin `FormRequest::create()`, nu `newInstanceWithoutConstructor()`.**
 * `Illuminate\Http\Request` (părintele) nu are constructor special — `static::create($uri,
 * $method, $parameters)` construiește o instanță REALĂ, complet funcțională, fără niciun
 * bypass de reflecție. Ce lipsește e doar containerul (`setContainer()`) și rezolvatorul de
 * rută (`setRouteResolver()`), ambele setabile după construcție.
 *
 * **De ce e sigur să chemi `rules()` fără o cerere HTTP reală.** Verificat manual, clasă cu
 * clasă (42 de fișiere, 41 de `FormRequest`-uri — al 42-lea e
 * `Orders/Concerns/ValidatesOrderLineDiscount`, un trait, exclus automat de `class_exists()`):
 * niciun `rules()` din acest proiect interoghează baza la CONSTRUCȚIA array-ului. `Rule::
 * exists()`/`Rule::unique()->where(fn ($query) => ...)` string-ifică închiderile abia la
 * VALIDARE (`Stringable::__toString()`), nu la citirea array-ului — deci un `$this->input(...)`
 * sau un `$this->route(...)` scris în interiorul unei asemenea închideri nu se execută aici.
 * Rămân exact DOUĂ excepții, ambele acoperite mai jos, în `fakeRoute()`:
 *   - `StorePaymentRequest::rules()` citește `$invoice->balance_due` IMEDIAT (nu într-o
 *     închidere amânată) — rezolvatorul de rută întoarce un `Invoice` real, needsalvat;
 *   - `UpdateVariantRequest::rules()` cheamă `.ignore($this->route('variant'))` imediat, dar
 *     `Unique::ignore()` doar ATRIBUIE valoarea (nicio interogare) — `null` (implicitul
 *     rezolvatorului fals de mai jos) e sigur.
 * Singura dependință reală rămasă e `TenantScope::requireCurrentTenantId()` (11 clase), de
 * aceea toată extragerea rulează sub `TenantContext::run()`, ca peste tot în suită — nu un
 * bypass, poarta unică (`.ai/rules/tenancy.md`).
 *
 * **Nicio clasă exclusă din scanare.** Verificarea de mai sus a confirmat că toate cele 41
 * de `FormRequest`-uri răspund la `rules()` în afara unei cereri HTTP reale, cu contextul
 * minimal construit aici — nu a fost nevoie de o listă albă de excepții.
 */
class ValidationAttributesTest extends TestCase
{
    use ScansPhpSource;

    // -- (a) Validator::make() direct — un câmp compus și unul de-un-cuvânt -------------

    public function test_generic_validator_messages_translate_field_names_to_french(): void
    {
        App::setLocale('fr');

        $validator = ValidatorFacade::make([], [
            'credit_terms' => 'required',
            'first_name' => 'required',
        ]);
        $validator->fails();

        App::setLocale('en');

        $messages = $validator->errors()->all();

        $this->assertSame([
            'Le champ conditions de crédit est obligatoire.',
            'Le champ prénom est obligatoire.',
        ], $messages);

        foreach ($messages as $message) {
            $this->assertStringNotContainsString('credit terms', $message);
            $this->assertStringNotContainsString('first_name', $message);
            $this->assertStringNotContainsString('first name', $message);
        }
    }

    public function test_the_same_generic_messages_stay_english_by_default(): void
    {
        $validator = ValidatorFacade::make([], [
            'credit_terms' => 'required',
            'first_name' => 'required',
        ]);
        $validator->fails();

        $this->assertSame([
            'The credit terms field is required.',
            'The first name field is required.',
        ], $validator->errors()->all());
    }

    // -- (b) gate-ul de reflecție: fiecare cheie din rules() are attributes în AMBELE limbi

    public function test_every_form_request_rule_key_has_a_validation_attribute_in_both_locales(): void
    {
        $en = require base_path('lang/en/validation.php');
        $fr = require base_path('lang/fr/validation.php');

        $missing = [];

        foreach ($this->ruleKeysByClass() as $class => $keys) {
            foreach ($keys as $key) {
                if (! array_key_exists($key, $en['attributes'])) {
                    $missing[] = "{$class}: '{$key}' lipsește din lang/en/validation.php → attributes";
                }

                if (! array_key_exists($key, $fr['attributes'])) {
                    $missing[] = "{$class}: '{$key}' lipsește din lang/fr/validation.php → attributes";
                }
            }
        }

        sort($missing);

        $this->assertSame(
            [],
            $missing,
            "Chei din rules() fără intrare în validation.attributes (I18N-01):\n".implode("\n", $missing),
        );
    }

    /**
     * Gardă anti-„vacuous truth": dacă scanarea directorului sau extragerea cheilor s-ar
     * rupe (cale greșită, o clasă care începe brusc să arunce), testul de mai sus ar trece
     * verde fără să fi verificat nimic — exact capcana pe care restul suitei o numește
     * explicit lângă fiecare scanare de acest fel (`ArchitectureTest::modelClasses()`).
     */
    public function test_the_reflection_scan_actually_finds_form_requests_and_rule_keys(): void
    {
        $byClass = $this->ruleKeysByClass();

        $this->assertGreaterThanOrEqual(
            40,
            count($byClass),
            'app/Http/Requests pare incomplet scanat — verifică calea sau class_exists().',
        );

        $totalKeys = array_sum(array_map('count', $byClass));

        $this->assertGreaterThanOrEqual(
            100,
            $totalKeys,
            'Numărul total de chei extrase din rules() a scăzut suspect de mult — verifică fakeRoute()/TenantContext.',
        );
    }

    // -- (c) eroare HTTP reală, câmp compus, în franceză ---------------------------------

    public function test_a_compound_field_validation_error_renders_in_french_over_http(): void
    {
        $marlin = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $owner = $this->makeMember($marlin, 'owner@throughput.dev', Permissions::OWNER);
        $owner->forceFill(['locale' => 'fr'])->save();
        $this->clearDatabaseTenantContext();

        $this->actingAs($owner)
            ->from('/marlin/accounts/create')
            ->post('/marlin/accounts', [
                'name' => 'Acme',
                'status' => 'prospect',
                'credit_terms' => 'net_30',
                'billing_address' => ['postal_code' => str_repeat('1', 21)],
            ])
            ->assertSessionHasErrors([
                'billing_address.postal_code' => 'Le champ code postal de l’adresse de facturation ne doit pas contenir plus de 20 caractères.',
            ]);
    }

    public function test_the_same_compound_field_error_stays_english_by_default(): void
    {
        $marlin = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $owner = $this->makeMember($marlin, 'owner@throughput.dev', Permissions::OWNER);
        $this->clearDatabaseTenantContext();

        $this->actingAs($owner)
            ->from('/marlin/accounts/create')
            ->post('/marlin/accounts', [
                'name' => 'Acme',
                'status' => 'prospect',
                'credit_terms' => 'net_30',
                'billing_address' => ['postal_code' => str_repeat('1', 21)],
            ])
            ->assertSessionHasErrors([
                'billing_address.postal_code' => 'The billing address postal code field must not be greater than 20 characters.',
            ]);
    }

    // -- helpers de reflecție -------------------------------------------------------------

    /**
     * Un singur tenant fals, un singur `TenantContext::run()` care înfășoară TOATĂ
     * extragerea — nu câte o tranzacție per clasă (42 de tranzacții imbricate n-ar aduce
     * nimic în plus, doar timp de rulare).
     *
     * @return array<class-string<FormRequest>, list<string>>
     */
    private function ruleKeysByClass(): array
    {
        $tenant = $this->makeTenant('reflection', 'Reflection Scan Co.');
        $this->clearDatabaseTenantContext();

        return TenantContext::run($tenant, function (): array {
            $result = [];

            foreach ($this->formRequestClasses() as $class) {
                $result[$class] = $this->ruleKeysFor($class);
            }

            return $result;
        });
    }

    /**
     * Toate clasele `FormRequest` din `app/Http/Requests/**`, prin PSR-4 pe calea
     * fișierului — nu o listă scrisă de mână, care ar putea rămâne în urmă la primul
     * `php artisan make:request`. `class_exists()` exclude natural fișierele care NU
     * declară o clasă cu acest nume (traitul `ValidatesOrderLineDiscount`).
     *
     * @return list<class-string<FormRequest>>
     */
    private function formRequestClasses(): array
    {
        $classes = [];

        foreach ($this->phpFilesIn(app_path('Http/Requests')) as $file) {
            $relative = Str::after($this->relative($file), 'app/Http/Requests/');
            $class = 'App\\Http\\Requests\\'.str_replace('/', '\\', Str::beforeLast($relative, '.php'));

            if (! class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);

            if ($reflection->isAbstract() || ! $reflection->isSubclassOf(FormRequest::class)) {
                continue;
            }

            $classes[] = $class;
        }

        sort($classes);

        return $classes;
    }

    /**
     * @param  class-string<FormRequest>  $class
     * @return list<string>
     */
    private function ruleKeysFor(string $class): array
    {
        /** @var FormRequest $request */
        $request = $class::create('/validation-attributes-reflection-test', 'POST', []);
        $request->setContainer($this->app);
        $request->setRouteResolver(fn () => $this->fakeRoute());

        return array_keys($request->rules());
    }

    /**
     * Rezolvator de rută minimal — implementează DOAR `parameter()`, singura metodă pe care
     * `Illuminate\Http\Request::route()` o apelează. `invoice` întoarce un `Invoice` real
     * (needsalvat) pentru `StorePaymentRequest::rules()`, care îi citește `balance_due`
     * imediat; orice alt nume de parametru întoarce `null`, sigur pentru restul pachetului
     * (vezi docblock-ul clasei pentru inventarul complet al celor două excepții).
     */
    private function fakeRoute(): object
    {
        $invoice = new Invoice(['balance_due' => 999]);

        return new class($invoice)
        {
            public function __construct(private readonly Invoice $invoice) {}

            public function parameter(string $name, mixed $default = null): mixed
            {
                return $name === 'invoice' ? $this->invoice : $default;
            }
        };
    }
}
