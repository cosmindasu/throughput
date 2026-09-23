<?php

namespace Tests\Feature\I18n;

use App\Actions\Members\InviteMemberAction;
use App\Mail\DataExportReadyMail;
use App\Mail\DunningPaymentFailedMail;
use App\Mail\MemberInvitationMail;
use App\Mail\ReportDeliveryMail;
use App\Mail\SubscriptionCanceledMail;
use App\Mail\SubscriptionUnpaidMail;
use App\Models\Account;
use App\Models\Invoice;
use App\Models\OrderLine;
use App\Services\Tenancy\TenantContext;
use App\Support\Permissions;
use Carbon\Carbon;
use Database\Factories\ProductFactory;
use Database\Factories\VariantFactory;
use Illuminate\Http\Request;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\View;
use Tests\Concerns\CreatesInvoices;
use Tests\TestCase;

/**
 * I18N-02/I18N-06/A11Y-07/I18N-05 — un singur fișier de test pentru cele patru cerințe ale
 * acestui lot, fiindcă toate patru se ating pe SUPRAFAȚA de output vizibilă a e-mailurilor și
 * a PDF-urilor (nu pe mecanismul intern): un client care primește un e-mail de facturare, o
 * invitație sau o factură nu vede „un catalog nou" sau „un `LocaleFormat` nou" — vede text
 * francez complet, un `<html lang>` corect și o dată franțuzească.
 *
 * I18N-02 — `resources/views/billing/mail/{canceled,unpaid,payment-failed}.blade.php` erau
 * 100% engleze, deși subiectul (`trans('mail.subscription_canceled.subject', …)`) era deja
 * tradus — un gol vizibil doar cuiva care deschide EFECTIV emailul, nu doar antetul.
 *
 * I18N-06 — `MemberInvitationMail` avea `$locale` „NEcablat încă": `InviteMemberAction` trimitea
 * fără parametru, deci randarea cădea pe limba AMBIENTALĂ a workerului la momentul livrării, nu
 * pe limba cererii care a trimis invitația (ADR-022). Testul de mai jos reproduce EXACT
 * scenariul: worker cu altă limbă ambientală decât cea capturată la dispecerizare, pe modelul
 * `tests/Feature/I18n/JobLocaleLeakTest.php`.
 *
 * A11Y-07 — cele 6 `Mailable`-uri randau fragmente `<p>` fără `<!DOCTYPE html>`/`<html lang>` —
 * WCAG 3.1.1 „Language of Page" încălcat pentru fiecare email tranzacțional trimis.
 *
 * I18N-05 — factura PDF (`invoices/pdf/invoice.blade.php`) folosea
 * `Carbon::toFormattedDateString()`, fixat pe engleză indiferent de `App::getLocale()`.
 */
class MailLocalizationTest extends TestCase
{
    use CreatesInvoices;

    /**
     * Cele 6 `Mailable`-uri din `app/Mail/`, fiecare construit cu argumentele scalare minime
     * necesare randării (fără atașamente REALE pe disc — `render()` nu le citește, doar
     * `send()`, vezi `Illuminate\Mail\Mailable::ensureAttachmentsAreHydrated()`).
     *
     * @return list<Mailable>
     */
    private function allSixMailables(string $locale): array
    {
        return [
            new SubscriptionCanceledMail('Marlin Fasteners & Supply Co.', 'marlin', locale: $locale),
            new SubscriptionUnpaidMail('Marlin Fasteners & Supply Co.', 'marlin', locale: $locale),
            new DunningPaymentFailedMail('Marlin Fasteners & Supply Co.', 'marlin', 2, locale: $locale),
            new MemberInvitationMail(
                workspaceName: 'Marlin Fasteners & Supply Co.',
                workspaceSlug: 'marlin',
                invitedByName: 'Owner Person',
                roleName: 'Agent',
                acceptUrl: 'https://throughput.test/invitations/abc123/accept',
                expiresInDays: 7,
                locale: $locale,
            ),
            new ReportDeliveryMail(
                reportName: 'Deal Velocity',
                formatLabel: 'csv',
                rowCount: 12,
                attachmentDisk: 'local',
                attachmentPath: 'reports/deal-velocity.csv',
                attachmentName: 'deal-velocity.csv',
                attachmentMime: 'text/csv',
                locale: $locale,
            ),
            new DataExportReadyMail(
                workspaceName: 'Marlin Fasteners & Supply Co.',
                requestedByName: 'Owner Person',
                downloadUrl: 'https://throughput.test/marlin/settings/data-export/1/download',
                expiresOn: '2026-10-01',
                retentionDays: 7,
                locale: $locale,
            ),
        ];
    }

    public function test_all_six_mailables_render_with_html_lang_fr(): void
    {
        foreach ($this->allSixMailables('fr') as $mailable) {
            $html = $mailable->render();

            $this->assertStringContainsString(
                '<html lang="fr">',
                $html,
                $mailable::class.' nu randează <html lang="fr">.',
            );
            $this->assertStringContainsString('<meta charset="utf-8">', $html);
        }
    }

    public function test_all_six_mailables_render_with_html_lang_en_by_default(): void
    {
        foreach ($this->allSixMailables('en') as $mailable) {
            $html = $mailable->render();

            $this->assertStringContainsString(
                '<html lang="en">',
                $html,
                $mailable::class.' nu randează <html lang="en">.',
            );
        }
    }

    /**
     * I18N-02 — corpul FRANCEZ al celor 3 email-uri de facturare. Verifică ATÂT prezența
     * frazelor franceze CÂT ȘI absența celor engleze-cheie: un `__()` care cade pe fallback
     * (cheie lipsă în `lang/fr/mail.php`) ar reda engleza sub `App::setLocale('fr')`, fără
     * nicio eroare — exact ce prindea `MemberInvitationMailLocaleTest` pentru invitație.
     */
    public function test_subscription_canceled_mail_renders_the_body_in_french(): void
    {
        $html = (new SubscriptionCanceledMail('Marlin Fasteners & Supply Co.', 'marlin', locale: 'fr'))->render();

        $this->assertStringContainsString('Bonjour,', $html);
        $this->assertStringContainsString(
            '<strong>Marlin Fasteners &amp; Supply Co.</strong> a été annulé',
            $html,
        );
        $this->assertStringContainsString('Réactivez l’abonnement depuis la page de facturation', $html);
        $this->assertStringContainsString('— Throughput', $html);

        $this->assertStringNotContainsString('subscription was canceled', $html);
        $this->assertStringNotContainsString('Reactivate from the billing page', $html);
    }

    public function test_subscription_unpaid_mail_renders_the_body_in_french(): void
    {
        $html = (new SubscriptionUnpaidMail('Marlin Fasteners & Supply Co.', 'marlin', locale: 'fr'))->render();

        $this->assertStringContainsString('Bonjour,', $html);
        $this->assertStringContainsString('désormais marqué <strong>impayé</strong>', $html);
        $this->assertStringContainsString('Mettez à jour le moyen de paiement depuis la page de facturation', $html);

        $this->assertStringNotContainsString('marked <strong>unpaid</strong>', $html);
        $this->assertStringNotContainsString('Update the payment method', $html);
    }

    public function test_dunning_payment_failed_mail_renders_the_body_in_french(): void
    {
        $html = (new DunningPaymentFailedMail('Marlin Fasteners & Supply Co.', 'marlin', 3, locale: 'fr'))->render();

        $this->assertStringContainsString('Bonjour,', $html);
        $this->assertStringContainsString('(tentative 3)', $html);
        $this->assertStringContainsString('Pour éviter toute interruption', $html);

        $this->assertStringNotContainsString('payment attempt for the', $html);
        $this->assertStringNotContainsString('(attempt 3)', $html);
    }

    /**
     * I18N-06 — `.ai/rules/tenancy.md:123-138`/`JobLocaleLeakTest`: reproduce exact
     * scenariul pentru care `MemberInvitationMail::$locale` trebuia cablat.
     * `InviteMemberAction::send()` captează `App::getLocale()` CÂT ÎNCĂ e activ locale-ul
     * cererii FR ('fr'); worker-ul care randează mai târziu e simulat aici cu o limbă
     * ambientală DIFERITĂ ('en') — dacă parametrul n-ar fi transmis explicit, randarea ar
     * cădea pe engleza ambientală de mai jos, nu pe franceza cererii.
     */
    public function test_member_invitation_keeps_the_requesting_locale_under_a_different_ambient_locale(): void
    {
        Mail::fake();

        $tenant = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $owner = $this->makeMember($tenant, 'owner@throughput.dev', Permissions::OWNER);

        // Simulează `App\Http\Middleware\SetLocale`, care ar fi fixat deja `App::getLocale()`
        // la limba cererii ÎNAINTE ca `InvitationController` să apeleze `InviteMemberAction`.
        App::setLocale('fr');

        TenantContext::run($tenant, function () use ($tenant, $owner): void {
            app()->instance('tenant', $tenant);

            app(InviteMemberAction::class)->execute(
                $owner,
                'invitee@example.com',
                Permissions::AGENT,
                Request::create('/'),
            );
        });

        $this->clearDatabaseTenantContext();

        // Worker-ul de coadă rulează cu ALTĂ limbă ambientală — simulată deliberat diferit,
        // ca să dovedească faptul că randarea NU depinde de ea.
        App::setLocale('en');

        Mail::assertQueued(MemberInvitationMail::class, function (MemberInvitationMail $mailable): bool {
            $html = $mailable->render();

            return str_contains($html, '<html lang="fr">')
                && str_contains($html, '>Accepter l’invitation<')
                && ! str_contains($html, 'invited you to join');
        });
    }

    /**
     * I18N-05 — data pe factura PDF urmează limba cererii, nu convenția engleză a lui
     * `Carbon::toFormattedDateString()`. `issue_date` e suprascris ÎN MEMORIE la o dată FIXĂ
     * (nu relativă la `now()` din fixtura comună), ca aserțiunea pe șirul FRANCEZ EXACT să
     * nu depindă de ziua rulării — exact valoarea măsurată în container pentru ICU 78.1
     * (`LocaleFormatDateTest`): fără spații insecabile speciale, doar ASCII.
     */
    public function test_invoice_pdf_date_renders_in_french(): void
    {
        $tenant = $this->makeTenant('marlin', 'Marlin Fasteners & Supply Co.');
        $owner = $this->makeMember($tenant, 'owner@throughput.dev', Permissions::OWNER);

        $invoice = TenantContext::run($tenant, function () use ($owner) {
            $account = new Account(['name' => 'Northwind Industrial Supply LLC']);
            $account->created_by = $owner->getKey();
            $account->save();

            $order = $this->confirmedOrder($account, $owner, 120);
            $invoice = $this->sentInvoice($order, 120);

            $product = (new ProductFactory)->create();
            $variant = (new VariantFactory)->create(['product_id' => $product->id]);

            $orderLine = new OrderLine([
                'order_id' => $order->getKey(),
                'variant_id' => $variant->getKey(),
                'description' => 'Test line',
                'quantity' => 4,
                'unit_price' => 30,
                'discount' => 0,
                'line_total' => 120,
            ]);
            $orderLine->save();

            return Invoice::query()
                ->with(['order.account', 'order.contact', 'order.orderLines.variant.product', 'tenant'])
                ->find($invoice->getKey());
        });

        $invoice->issue_date = Carbon::create(2026, 9, 23);
        $invoice->due_date = Carbon::create(2026, 10, 23);

        App::setLocale('fr');
        $french = View::make('invoices.pdf.invoice', ['invoice' => $invoice])->render();
        App::setLocale('en');
        $english = View::make('invoices.pdf.invoice', ['invoice' => $invoice])->render();

        $this->assertStringContainsString('23 sept. 2026', $french);
        $this->assertStringContainsString('23 oct. 2026', $french);
        $this->assertStringNotContainsString('Sep 23, 2026', $french);

        $this->assertStringContainsString('Sep 23, 2026', $english);
        $this->assertStringNotContainsString('23 sept. 2026', $english);
    }
}
