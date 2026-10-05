<?php

namespace Database\Seeders\Demo;

use App\Models\Account;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Product;
use App\Models\Tenant;
use Database\Seeders\Support\ActivityLogRecorder;
use Database\Seeders\Support\DemoClock;
use Database\Seeders\Support\DemoId;
use Database\Seeders\Support\Rand;
use Illuminate\Console\Command;

/**
 * Coada RECENTĂ a jurnalului de activitate — ultimele două săptămâni dintr-un workspace viu.
 *
 * ## De ce e nevoie de un seeder separat
 *
 * Celelalte seedere scriu în jurnal ca efect secundar al entităților pe care le creează, deci
 * produc exact două verbe: `created` și `updated`. Enum-ul are nouă. Mai rău, intrările lor
 * sunt datate odată cu entitatea, iar singurul lucru din setul demo care ajunge aproape de
 * ziua de azi sunt tranzițiile de etapă ale afacerilor — așa că „Recent activity" de pe
 * dashboard arăta de opt ori la rând aceeași frază („Moved … to another stage"), pe un
 * produs al cărui jurnal de activitate e un argument de vânzare.
 *
 * Măsurat înainte: 53.277 de rânduri pe tenant, două verbe, iar cele mai recente 40 toate
 * mutări de etapă.
 *
 * ## Ce scrie
 *
 * Amestecul de mai jos NU e aleatoriu pe toate verbele deopotrivă: e proporția unei
 * săptămâni de lucru reale. Autentificările sunt cel mai frecvent eveniment dintr-un sistem
 * adevărat, dar aici sunt deliberat ținute pe la o cincime din total — un feed în care patru
 * rânduri din cinci spun „cineva s-a autentificat" e la fel de inutil ca unul monoton, doar
 * pe altă notă. Restul sunt consecințe: o factură încasată, o comandă expediată, o listă
 * exportată, un import, o reatribuire în masă.
 *
 * Fiecare rând trimite la o înregistrare REALĂ (`auditable_id` citit din tabelele deja
 * semănate), ca `ActivityEntryResource::subjectName()` să aibă ce rezolva. Singura excepție
 * e `deleted`, care primește un id inexistent — exact ce rămâne în urma unei ștergeri, și
 * singura cale ca feed-ul să nu promită un link către un contact care încă există.
 */
final class ActivityVarietySeeder
{
    /**
     * Câte zile în urmă se întinde coada.
     *
     * CINCI, nu paisprezece: lanțul comandă → factură → expediere produce singur vreo
     * șaizeci de rânduri pe zi într-un workspace care face douăzeci de comenzi pe zi, deci o
     * coadă întinsă pe două săptămâni se diluează sub el și niciunul dintre verbele de aici
     * nu mai ajunge în primele rânduri ale feed-ului — adică fix unde trebuiau să fie.
     */
    private const DAYS = 5;

    /**
     * Cât din coadă sunt autentificări, ca fracție din evenimentele de business. Într-un
     * sistem real ar fi mult mai multe; aici contează ce se vede în primele opt rânduri ale
     * feed-ului, iar un registru de prezență e la fel de inutil ca un feed monoton, doar pe
     * altă notă. O opta dintre ele e o încercare EȘUATĂ — cineva și-a greșit parola.
     */
    private const SESSION_SHARE = 0.25;

    /**
     * @param  array{owner_id: string, demo_agent_id: ?string, pool: list<array{id: string, role: string}>}  $staff
     */
    public function run(Tenant $tenant, array $staff, ?Command $command, ActivityLogRecorder $activityLog): void
    {
        $actors = array_column($staff['pool'], 'id');

        if ($actors === []) {
            return;
        }

        // Eșantioane mici, nu tabele întregi: feed-ul are nevoie de câteva zeci de subiecte,
        // iar `inRandomOrder()` pe 50.000 de comenzi ar fi cea mai scumpă interogare a
        // întregului seed. Ordinea e cea naturală a cheii — pentru un demo, „primele N" e la
        // fel de bun ca „N la întâmplare", și e un index scan în loc de o sortare completă.
        $accounts = Account::query()->limit(40)->pluck('name', 'id')->all();
        $contacts = Contact::query()->limit(40)->pluck('id')->all();
        $deals = Deal::query()->limit(40)->pluck('id')->all();
        $invoices = Invoice::query()->where('status', Invoice::STATUS_PAID)->limit(40)->pluck('id')->all();
        $products = Product::query()->limit(40)->pluck('id')->all();

        $command?->getOutput()->writeln('  <fg=cyan>›</> Activity log — recent tail');

        /** @var list<array{0: string, 1: ?string, 2: ?string, 3: ?array<string, mixed>, 4: ?array<string, mixed>}> $events */
        $events = [];
        $add = function (string $action, ?string $type = null, ?string $id = null, ?array $newValues = null, ?array $oldValues = null) use (&$events): void {
            $events[] = [$action, $type, $id, $newValues, $oldValues];
        };

        $pick = fn (array $list): ?string => $list === [] ? null : (string) $list[array_rand($list)];

        // Facturi încasate: ambele forme derivate (`invoice_paid`, `order_shipped`) sunt
        // `updated` în coloană, iar `ActivityKind` le distinge din `new_values` — deci forma
        // de mai jos nu e decor, e chiar ce citește derivarea.
        //
        // `order_shipped` NU se scrie aici: `StockAndOrdersSeeder` îl produce deja pentru
        // fiecare comandă expediată, datat pe expedierea reală. Dublat, ar fi o a doua
        // expediere a aceleiași comenzi, la altă oră.
        foreach (array_slice($invoices, 0, 6) as $invoiceId) {
            $add('updated', Invoice::class, (string) $invoiceId, ['status' => Invoice::STATUS_PAID], ['status' => Invoice::STATUS_SENT]);
        }

        // Exporturi: lista pe care cineva a tras-o ca s-o ducă în altă parte. Fără subiect
        // individual — un export e despre o listă, nu despre un rând.
        foreach ([Account::class, Deal::class, Invoice::class, Order::class, Contact::class] as $type) {
            $add('exported', $type);
        }

        $add('imported', Contact::class);
        if (Rand::bool(60)) {
            $add('imported', Account::class);
        }

        for ($i = 0; $i < 3; $i++) {
            $add('bulk_action', Account::class);
        }

        $add('role_changed');

        // Ștergeri: `DemoId::next()` dă un id care nu corespunde niciunui rând, fiindcă ASTA
        // e starea de după o ștergere. `subjectName()` întoarce atunci `null`, iar feed-ul
        // nu promite un link către ceva ce nu mai există.
        for ($i = 0; $i < 2; $i++) {
            $add('deleted', Contact::class, DemoId::next());
        }
        $add('deleted', Deal::class, DemoId::next());

        // Creări și editări de rutină, pe înregistrări reale.
        foreach (array_slice(array_keys($accounts), 0, 4) as $accountId) {
            $add('created', Account::class, (string) $accountId);
        }
        foreach (array_slice($contacts, 0, 5) as $contactId) {
            $add('created', Contact::class, (string) $contactId);
        }
        foreach (array_slice($deals, 0, 4) as $dealId) {
            $add('created', Deal::class, (string) $dealId);
        }

        for ($i = 0; $i < 5; $i++) {
            $add('updated', Account::class, $pick(array_keys($accounts)), ['industry' => 'Industrial Equipment']);
        }
        for ($i = 0; $i < 4; $i++) {
            $add('updated', Product::class, $pick($products), ['is_active' => true]);
        }

        // Autentificările se numără DUPĂ celelalte, ca proporție din ele, nu ca o constantă.
        // Un număr fix ar da un amestec corect pe setul demo complet și un registru de
        // prezență pe orice tenant cu puține entități — fix cazul în care feed-ul are cel
        // mai mult nevoie să arate că produsul face ceva.
        $sessions = max(4, (int) round(count($events) * self::SESSION_SHARE));
        // Cel puțin UNA eșuată, oricât de scurtă ar fi coada: `login_failed` e singurul
        // eveniment de securitate din enum, iar un jurnal de activitate fără el nu arată la
        // ce e bun. La scara setului demo complet, proporția rămâne cea de ~1 din 8.
        $failed = max(1, intdiv($sessions, 8));
        for ($i = 0; $i < $sessions - $failed; $i++) {
            $add('login');
        }
        for ($i = 0; $i < $failed; $i++) {
            $add('login_failed');
        }

        foreach ($events as [$action, $type, $id, $newValues, $oldValues]) {
            $activityLog->record(
                $tenant->id,
                $actors[array_rand($actors)],
                $action,
                $type,
                $id,
                DemoClock::recentMoment(self::DAYS),
                $oldValues,
                $newValues,
            );
        }

        $activityLog->flush();
    }
}
