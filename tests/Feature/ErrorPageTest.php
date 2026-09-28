<?php

namespace Tests\Feature;

use App\Support\ErrorPageStatus;
use Tests\TestCase;

/**
 * [[ADR-024]] — o cerere Inertia primește pagina din aplicație, orice altceva păstrează
 * vederile Blade. Garda contează în ambele sensuri: dacă Inertia n-ar primi pagina, vizitatorul
 * vede iar un modal gol; dacă Blade ar fi înlocuit, o eroare apărută când build-ul frontend
 * lipsește n-ar mai avea ce randa — exact motivul pentru care
 * `resources/views/errors/layout.blade.php` nu folosește `@vite(...)`.
 */
class ErrorPageTest extends TestCase
{
    public function test_an_inertia_request_gets_the_in_app_page(): void
    {
        $response = $this->withHeader('X-Inertia', 'true')
            ->withHeader('X-Inertia-Version', '1')
            ->get('/o-ruta-care-nu-exista');

        $response->assertStatus(404);
        $response->assertHeader('X-Inertia', 'true');

        $page = $response->json('props');

        $this->assertSame('Error', $response->json('component'));
        $this->assertSame(404, $page['status']);
        $this->assertSame(__('Not Found'), $page['title']);
        $this->assertNotSame('', $page['message']);
    }

    public function test_a_normal_page_load_still_gets_the_blade_view(): void
    {
        $response = $this->get('/o-ruta-care-nu-exista');

        $response->assertStatus(404);
        $response->assertHeaderMissing('X-Inertia');

        // Vederea Blade, nu pagina Inertia: textul e în corp, nu în props JSON.
        $response->assertSee(__('The page you are looking for could not be found or may have been moved.'), false);
    }

    /**
     * Statusul trebuie să rămână cel real. Un 404 servit cu 200 e mai rău decât un modal gol:
     * crawlerele și monitoarele îl citesc drept pagină validă.
     */
    public function test_the_original_status_code_survives(): void
    {
        $this->withHeader('X-Inertia', 'true')
            ->withHeader('X-Inertia-Version', '1')
            ->get('/o-ruta-care-nu-exista')
            ->assertStatus(404);
    }

    /**
     * `403.blade.php` preferă `$exception->getMessage()` când există, fiindcă acolo mesajul
     * chiar spune motivul refuzului. Pagina Inertia trebuie să facă la fel, altfel același
     * refuz s-ar explica diferit în funcție de cum a ajuns vizitatorul la el.
     */
    public function test_a_403_prefers_the_reason_from_the_exception(): void
    {
        $this->assertSame(
            'Only an Owner can change billing.',
            ErrorPageStatus::copyFor(403, 'Only an Owner can change billing.')['message']
        );

        $this->assertSame(
            __("You don't have permission to access this page."),
            ErrorPageStatus::copyFor(403, '')['message']
        );
    }

    /**
     * Reversul regulii de mai sus: pe orice alt status, mesajul excepției e ignorat. Un 500
     * poartă detalii interne, iar vederile Blade nu-l folosesc nicăieri în afară de 403.
     */
    public function test_other_statuses_never_leak_the_exception_message(): void
    {
        foreach ([404, 419, 429, 500, 503] as $status) {
            $copy = ErrorPageStatus::copyFor($status, 'SQLSTATE[42P01]: undefined_table: tenants');

            $this->assertStringNotContainsString('SQLSTATE', $copy['message']);
            $this->assertSame(__(ErrorPageStatus::COPY[$status][1]), $copy['message']);
        }
    }

    /**
     * Cele două randări trebuie să acopere ACELEAȘI statusuri. Un status adăugat într-un
     * singur loc e tăcut altfel: vizitatorul vede pagina într-un caz și modalul gol în
     * celălalt, în funcție de cum a ajuns acolo.
     */
    public function test_every_supported_status_has_a_blade_view_with_the_same_copy(): void
    {
        foreach (ErrorPageStatus::COPY as $status => [$titleKey, $messageKey]) {
            $path = resource_path("views/errors/{$status}.blade.php");

            $this->assertFileExists(
                $path,
                "Statusul {$status} e declarat în ErrorPageStatus, dar n-are vedere Blade."
            );

            $view = file_get_contents($path);

            $this->assertStringContainsString(
                $titleKey,
                $view,
                "`{$status}.blade.php` nu mai folosește cheia de titlu declarată în ErrorPageStatus. "
                .'Cele două randări ar afișa texte diferite pentru aceeași eroare.'
            );

            $this->assertStringContainsString(
                $messageKey,
                $view,
                "`{$status}.blade.php` nu mai folosește cheia de mesaj declarată în ErrorPageStatus."
            );
        }
    }

    /**
     * Reversul: o vedere Blade adăugată fără intrare în `ErrorPageStatus` ar rămâne
     * invizibilă pe calea Inertia.
     */
    public function test_no_blade_view_is_missing_from_the_supported_list(): void
    {
        $views = glob(resource_path('views/errors/*.blade.php'));

        foreach ($views as $view) {
            $name = basename($view, '.blade.php');

            if (! ctype_digit($name)) {
                continue; // `layout.blade.php`
            }

            $this->assertTrue(
                ErrorPageStatus::supports((int) $name),
                "`{$name}.blade.php` există, dar statusul nu e în ErrorPageStatus — pe o cerere "
                .'Inertia ar ajunge tot într-un modal gol.'
            );
        }
    }
}
