<?php

namespace App\Providers;

use App\Mail\InterceptingMailManager;
use App\Models\Tenant;
use App\Support\Members\DeactivatedMemberIds;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\ServiceProvider;
use Laravel\Cashier\Cashier;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // FR-TEN-04 — cache-ul „(deactivated)" ține stare ÎN instanță (cheiată pe
        // tenant), deci trebuie o SINGURĂ instanță per cerere/job: `scoped()`, aruncată
        // de worker-ul de coadă înainte de fiecare job (`QueueServiceProvider`), altfel
        // un al doilea `app(DeactivatedMemberIds::class)` ar crea o instanță nouă, cu
        // propriul cache gol, chiar în aceeași cerere.
        $this->app->scoped(DeactivatedMemberIds::class);

        // BR-DEMO-02, specs.md §22.3, plan §10 — decorează ORICE mailer cu interceptarea de
        // demo (`App\Mail\Transport\DemoInterceptingTransport`), indiferent de driverul
        // configurat (`log` local, `resend` în producție — ADR-009).
        //
        // `extend()`, NU `singleton()`: `Illuminate\Mail\MailServiceProvider` implementează
        // `DeferrableProvider` — se încarcă abia la PRIMA rezolvare a `mail.manager`/`mailer`,
        // moment în care `Application::registerDeferredProvider()` re-leagă binding-ul
        // NECONDIȚIONAT (verificat în sursă), suprascriind orice `singleton()` pus aici mai
        // devreme, în `register()`, ÎNAINTE ca ceva să fi cerut vreodată mail-ul. Un
        // `extend()` nu are această problemă: extenderele unui container se aplică DUPĂ
        // rezolvare, indiferent cine a legat binding-ul ultimul — robust la ordinea de
        // încărcare a providerilor amânați.
        $this->app->extend(
            'mail.manager',
            fn (): InterceptingMailManager => new InterceptingMailManager($this->app),
        );

        // Billable e TENANTUL, nu utilizatorul (ADR-006): abonamentul e al organizației
        // și nu trebuie să dispară când pleacă persoana care a introdus cardul.
        Cashier::useCustomerModel(Tenant::class);

        // Cashier își înregistrează singur două rute, în afara oricărui grup de middleware:
        // `GET /stripe/payment/{id}` (pagina de confirmare SCA) și `POST /stripe/webhook`.
        //
        // Amândouă sunt nepotrivite aici, din motive diferite: prima e o pagină HTML publică
        // fără `noindex` (încalcă FR-PUB-04, prins de NoIndexTest), a doua e un endpoint
        // deschis — `VerifyWebhookSignature` se atașează doar când `cashier.webhook.secret`
        // e setat, iar în Faza 1 nu e. Oricum avem nevoie de handler propriu: §12.3 cere
        // deduplicare pe `webhook_events` cu idempotență, nu comportamentul implicit.
        //
        // Se re-înregistrează explicit, în grupul `web`, în Faza 5.
        Cashier::ignoreRoutes();

        // Notă, verificată în vendor (nu presupusă): Cashier 16 și Sanctum 4 doar
        // PUBLICĂ migrațiile, nu le încarcă din pachet — deci nu există `ignoreMigrations()`
        // de chemat și nici risc ca variantele lor (cu chei auto-incrementate, și cu
        // coloanele Stripe puse pe `users`) să ruleze în paralel cu ale noastre din
        // `database/migrations/`, rescrise pe chei ULID (BR-DATA-01).
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Fără învelișul `{"data": …}` al lui JsonResource.
        //
        // Regula 1 din plan §1.2 cere ca fiecare prop Inertia să treacă printr-un Resource —
        // dar învelișul implicit face ca `WorkspaceResource::make($tenant)` să ajungă în
        // React ca `workspace.data.name`, nu `workspace.name`. Contractul de props (și
        // componentele scrise după el) citesc forma fără înveliș, așa că React primea
        // `undefined` fără nicio eroare: comutator de workspace gol, feed de activitate gol.
        // Prins de DashboardTest, nu la review.
        //
        // Când API-ul public din §18 va avea nevoie de un înveliș, acesta se declară
        // explicit acolo (`public static $wrap`), pe resursele lui — nu global, pe toate.
        JsonResource::withoutWrapping();
    }
}
