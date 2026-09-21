<?php

namespace Tests\Feature\Notifications;

use App\Notifications\MembershipRecordsNeedNewOwnerNotification;
use Illuminate\Support\Facades\App;
use Tests\TestCase;

/**
 * ADR-022, specs.md §15.8 FR-I18N-04/05 — pluralizare corectă EN/FR (plan „Lot I18N" Val 2:
 * „Str::plural() → trans_choice()"), inclusiv cazul FR „0 = singular": deși practic
 * notificarea nu se trimite la 0 (`App\Http\Controllers\Web\Settings\MembersController`
 * verifică `$counts['total'] > 0` înainte de dispecerizare), catalogul rămâne corect și pe
 * acel caz, pentru orice alt apelant viitor — un catalog corect DOAR pe calea fericită azi
 * folosită e exact genul de gaură pe care testul de acoperire (`i18n:coverage`) nu o prinde
 * (verifică prezența cheii, nu corectitudinea gramaticală a fiecărei ramuri).
 *
 * Randare DIRECTĂ (`toMail()`), cu `App::setLocale()` manual — locale-ul AUTOMAT per
 * destinatar (`HasLocalePreference`, `Illuminate\Notifications\NotificationSender`) e deja
 * verificat separat în `tests/Feature/Preferences/LocaleTest.php`
 * (`test_preferred_locale_feeds_the_native_laravel_notification_localization_contract`) —
 * nu se reface aici acea infrastructură, doar CONȚINUTUL propriu al acestei notificări.
 */
class MembershipRecordsNeedNewOwnerNotificationTest extends TestCase
{
    private function notify(int $openDeals, int $activeOrders): object
    {
        $notification = new MembershipRecordsNeedNewOwnerNotification(
            deactivatedMemberName: 'Jane Doe',
            tenantName: 'Marlin Fasteners & Supply Co.',
            workspaceSlug: 'marlin',
            openDeals: $openDeals,
            activeOrders: $activeOrders,
        );

        return $notification->toMail((object) ['name' => 'Sam Owner']);
    }

    public function test_english_subject_pluralizes_with_zero_as_plural(): void
    {
        App::setLocale('en');

        $mail = $this->notify(openDeals: 0, activeOrders: 1);

        $this->assertSame('1 record needs a new owner', $mail->subject);
    }

    public function test_french_subject_pluralizes_with_zero_and_one_as_singular(): void
    {
        App::setLocale('fr');

        // BR-I18N (specs.md §15.8, plan Val 2) — franceza tratează 0 CA SINGULAR, spre
        // deosebire de engleză. `openDeals: 0` singur ar da 0, testat separat mai jos prin
        // liniile individuale; subiectul combină cele două numărători (total = 1).
        $mail = $this->notify(openDeals: 0, activeOrders: 1);

        $this->assertSame('1 enregistrement a besoin d\'un nouveau propriétaire', $mail->subject);
    }

    public function test_english_and_french_pluralize_the_deals_and_orders_lines_independently(): void
    {
        App::setLocale('en');
        $en = $this->notify(openDeals: 0, activeOrders: 2);
        $this->assertStringContainsString('0 open deals and 2 active orders are now unassigned.', $en->introLines[1]);

        App::setLocale('fr');
        $fr = $this->notify(openDeals: 0, activeOrders: 2);
        // 0 = singulier en français ("0 affaire ouverte"), au contraire de l'anglais.
        $this->assertStringContainsString('0 affaire ouverte et 2 commandes actives sont désormais sans propriétaire.', $fr->introLines[1]);
    }

    public function test_a_zero_total_subject_has_an_explicit_branch_in_both_locales(): void
    {
        // Gol găsit după Valul 3, exact în cazul pe care docblock-ul de mai sus îl declara
        // acoperit „pentru orice alt apelant viitor", dar pe care niciun test nu-l atingea:
        // engleza n-avea ramură `{0}`, deci `trans_choice(..., 0)` nu potrivea nicio condiție
        // scrisă și cădea pe ramura de rezervă a lui `MessageSelector`, singura care nu aplică
        // `trim()` — ieșea „ 0 records need a new owner", cu spațiu la început.
        //
        // `assertSame` pe șirul ÎNTREG, deliberat: `assertStringContainsString` ar fi trecut
        // verde și cu spațiul cu tot, adică exact peste defectul căutat.
        App::setLocale('en');
        $this->assertSame('0 records need a new owner', $this->notify(openDeals: 0, activeOrders: 0)->subject);

        App::setLocale('fr');
        // Franceza avea deja `[0,1]` — zero e singular acolo. Aserțiunea o fixează, ca o
        // eventuală „aliniere" a celor două cataloage să nu i-o rescrie pe modelul englez.
        $this->assertSame('0 enregistrement a besoin d\'un nouveau propriétaire', $this->notify(openDeals: 0, activeOrders: 0)->subject);
    }

    public function test_subjects_pluralize_correctly_above_one_in_both_locales(): void
    {
        App::setLocale('en');
        $en = $this->notify(openDeals: 2, activeOrders: 3);
        $this->assertSame('5 records need a new owner', $en->subject);

        App::setLocale('fr');
        $fr = $this->notify(openDeals: 2, activeOrders: 3);
        $this->assertSame('5 enregistrements ont besoin d\'un nouveau propriétaire', $fr->subject);
    }
}
