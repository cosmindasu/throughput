<?php

namespace App\Providers;

use App\Events\Activity\ModelWasRecorded;
use App\Listeners\Activity\WriteActivityLogEntry;
use App\Models\Account;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Product;
use App\Models\Variant;
use App\Observers\ActivityLogObserver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * ADR-007, specs.md §17 — instrumentarea LIVE a jurnalului de activitate: observers
 * Eloquent → event → queued listener, cod propriu (nu `owen-it/laravel-auditing`).
 *
 * Provider DEDICAT, nu un atribut `#[ObservedBy]` pe fiecare model — decizia lotului E,
 * argumentată în raport: `app/Providers/AppServiceProvider.php` e editat SIMULTAN de alt
 * agent în acest val, iar `app/Models/Invoice.php`/`Payment.php`/`TenantCarrierSetting.php`
 * aparțin altor loturi ale ACELUIAȘI val — niciun risc de coliziune pe fișiere comune sau
 * pe modele în curs de construcție în alt lot. Un singur loc, aici, controlează ȘI lista de
 * modele observate, ȘI maparea event → listener.
 *
 * **Lista de modele observate** — cele șase entități „care EXISTĂ azi" enumerate explicit
 * în task-ul lotului: Account, Contact, Deal, Product, Variant, Order. DELIBERAT ABSENTE:
 *
 *  - `Invoice`/`Payment`/`TenantCarrierSetting` — construite ACUM, în paralel, de alt lot
 *    (nu le atinge, nici măcar prin observare — vezi raportul pentru linia exactă cu care
 *    tabul „History" se montează pe pagina de factură, când va exista).
 *  - `Membership` (role_changed) — scrisă deja direct din
 *    `App\Http\Controllers\Web\Settings\MembersController` (alt lot, Faza 5, „Members &
 *    Invitations"); a o observa AICI ar produce fie rânduri duplicate, fie un conflict de
 *    format cu scrierea manuală deja existentă acolo.
 *  - `User` — ar loga `password`/`remember_token` prin ORICE modificare de profil, dincolo
 *    de ce BR-AUD-01 exclude explicit; niciun ecran din lista de mai sus nu cere un tab
 *    „History" pe utilizator.
 *
 * Un al șaptelea model, mai târziu: se adaugă în `observedModels()`, nu prin editarea
 * modelului însuși.
 *
 * **`Event::listen(ModelWasRecorded::class, WriteActivityLogEntry::class)` explicit, DIN
 * NOU** — istoricul contează aici, ca să nu se re-descopere aceeași capcană a treia oară.
 * Acest schelet Laravel (fără `EventServiceProvider`) are auto-descoperirea de evenimente
 * ACTIVĂ implicit, care scanează `app/Listeners` și leagă `WriteActivityLogEntry::
 * handle(ModelWasRecorded $event)` după tipul parametrului — un `Event::listen()` explicit,
 * pe lângă acea descoperire, înregistra listener-ul DE DOUĂ ORI pe dispatcher (dovedit de
 * `ActivityLogObserverTest`, 2 rânduri identice per creare). Fixul de atunci a fost
 * eliminarea apelului explicit de aici, cu nota „a dezactiva descoperirea ar fi cerut
 * `bootstrap/app.php`, fișier interzis acestui lot".
 *
 * Lotul de abonament (Faza 5, paralel) a găsit ACEEAȘI capcană pe propriile evenimente
 * (`SubscriptionBecameUnpaid`/`SubscriptionCanceled` — un singur `event()` trimitea DOUĂ
 * emailuri de tranziție) și a reparat-o la sursă: `bootstrap/app.php` are acum explicit
 * `->withEvents(discover: false)`, cu motivarea completă acolo. Asta rupea silențios acest
 * provider (fără descoperire, fără nicio înregistrare, `activity_log` rămânea gol — găsit
 * prin re-rularea suitei, nu presupus), deci apelul explicit REVINE aici, de data asta ca
 * SINGURA înregistrare din tot proiectul, nu ca duplicat al uneia implicite.
 */
class ActivityLogServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $observer = new ActivityLogObserver;

        foreach ($this->observedModels() as $model) {
            $model::observe($observer);
        }

        Event::listen(ModelWasRecorded::class, WriteActivityLogEntry::class);
    }

    /**
     * @return list<class-string<Model>>
     */
    private function observedModels(): array
    {
        return [
            Account::class,
            Contact::class,
            Deal::class,
            Product::class,
            Variant::class,
            Order::class,
            // Adăugat la integrarea Fazei 5, după ce lotul de facturare a aterizat:
            // §17.3/FR-AUD-02 listează explicit factura printre entitățile cu tab „History".
            // `Payment` NU e observat — nu apare în acea listă, iar plățile se citesc deja
            // pe pagina facturii, din propria lor secțiune.
            Invoice::class,
        ];
    }
}
