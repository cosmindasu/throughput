<?php

namespace Tests\Unit\Support;

use App\Support\JobErrorMessage;
use Illuminate\Support\Facades\App;
use Tests\TestCase;

/**
 * I18N-03 — `App\Support\JobErrorMessage`, mecanismul de codificare al `error_message`
 * (`bulk_operations`/`shipments`/`report_runs`/`data_export_requests`). Vezi docblock-ul
 * clasei pentru „de ce": un job de coadă nu are cererea al cărei locale ar trebui folosit,
 * deci scrie o cheie de catalog, nu text — traducerea se întâmplă abia la randare.
 *
 * `Tests\TestCase`, nu `PHPUnit\Framework\TestCase` (spre deosebire de
 * `tests/Unit/Support/LocaleFormatTest.php`): clasa foloseește `Lang::has()`/`__()`, care au
 * nevoie de translatorul din containerul Laravel — o funcție „pură" doar cu aplicația
 * complet ridicată.
 */
class JobErrorMessageTest extends TestCase
{
    public function test_it_round_trips_a_key_without_params(): void
    {
        $stored = JobErrorMessage::encode('job_errors.bulk.initiator_gone');

        $this->assertSame(
            trans('job_errors.bulk.initiator_gone'),
            JobErrorMessage::render($stored),
        );
    }

    public function test_it_round_trips_a_key_with_scalar_params(): void
    {
        $stored = JobErrorMessage::encode('job_errors.export.row_cap_exceeded', [
            'count' => 42,
            'format' => 'pdf',
            'cap' => 10,
        ]);

        $this->assertSame(
            trans('job_errors.export.row_cap_exceeded', ['count' => 42, 'format' => 'pdf', 'cap' => 10]),
            JobErrorMessage::render($stored),
        );
    }

    /**
     * Rânduri VECHI, scrise înainte de acest lot — text englez simplu, nu JSON — trec
     * NESCHIMBATE, nu ca eroare. Aceeași cale acoperă și mesajele EXTERNE (raportate de un
     * transportator, `ShippingLabelFailed`), deliberat necodificate — vezi docblock-ul clasei.
     */
    public function test_plain_old_text_passes_through_unchanged(): void
    {
        $legacy = 'The report definition no longer exists.';

        $this->assertSame($legacy, JobErrorMessage::render($legacy));
    }

    /**
     * O cheie codificată dar NECUNOSCUTĂ catalogului (`Lang::has()` fals — catalog
     * desincronizat, cheie redenumită) nu aruncă și nu pierde informația: rândul brut
     * (JSON-ul stocat) se întoarce neschimbat.
     */
    public function test_an_unknown_key_passes_through_unchanged(): void
    {
        $stored = JobErrorMessage::encode('job_errors.this_key_does_not_exist_anywhere');

        $this->assertSame($stored, JobErrorMessage::render($stored));
    }

    public function test_null_and_empty_string_pass_through_unchanged(): void
    {
        $this->assertNull(JobErrorMessage::render(null));
        $this->assertSame('', JobErrorMessage::render(''));
    }

    /**
     * I18N-03 — parametrul AMÂNAT (`translatedParam()`): valoarea brută a enum-ului
     * (`OrderStatus::Cancelled->value`, „cancelled") se stochează, nu eticheta — traducerea
     * se întâmplă în `render()`, în locale-ul curent al PROCESULUI care randează, nu al celui
     * care a scris rândul. Testat explicit în franceză, ca să nu treacă din întâmplare doar
     * fiindcă engleza e implicitul.
     */
    public function test_a_translated_param_resolves_in_the_current_render_time_locale(): void
    {
        App::setLocale('fr');

        $stored = JobErrorMessage::encode('job_errors.shipment.order_no_longer_open', [
            'status' => JobErrorMessage::translatedParam('enums.order_status.cancelled'),
        ]);

        $this->assertSame(
            trans('job_errors.shipment.order_no_longer_open', ['status' => trans('enums.order_status.cancelled')]),
            JobErrorMessage::render($stored),
        );
        $this->assertStringContainsString(trans('enums.order_status.cancelled'), JobErrorMessage::render($stored));
    }

    /**
     * Același rând STOCAT (scris o singură dată, de un job) se traduce diferit după cine îl
     * CITEȘTE — proba centrală că nimic nu s-a înghețat la scriere.
     */
    public function test_the_same_stored_value_renders_differently_per_locale(): void
    {
        $stored = JobErrorMessage::encode('job_errors.report.definition_missing');

        App::setLocale('en');
        $english = JobErrorMessage::render($stored);

        App::setLocale('fr');
        $french = JobErrorMessage::render($stored);

        $this->assertNotSame($english, $french);
        $this->assertSame(trans('job_errors.report.definition_missing', [], 'en'), $english);
        $this->assertSame(trans('job_errors.report.definition_missing', [], 'fr'), $french);
    }
}
