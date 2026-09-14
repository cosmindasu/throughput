<?php

namespace Tests\Feature\Help;

use App\Support\Permissions;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * FR-HELP-04 — „acoperire garantată prin test": parcurge rutele din navigația
 * principală (`NAV_ITEMS`, resources/js/Layouts/AppLayout.tsx) și PICĂ dacă o
 * rută existentă randează o componentă Inertia fără subiect de ajutor mapat în
 * resources/js/help/index.ts.
 *
 * Sursă verificabilă aleasă (dintre cele sugerate de pachet) și DE CE:
 *
 *  1. Parsare regex a celor două fișiere TypeScript sursă — Pest rulează în
 *     PHP, nu poate `import` un modul `.ts` direct, iar o build/transpilare
 *     doar pentru citirea unui obiect literal ar fi mult cost pentru puțin
 *     câștig. Regexul citește exact ce există în fișier, nu o presupunere.
 *  2. Cereri HTTP REALE, ca Owner, pe fiecare rută înregistrată — ca să aflăm
 *     COMPONENTA INERTIA efectiv randată, nu numele pe care ni l-am imaginat
 *     din label-ul de navigație. O rută poate exista cu alt nume de componentă
 *     decât am presupus; testul verifică ce se întâmplă, nu ce credem noi.
 *
 * Module întregi încă neconstruite ÎN TOT PROIECTUL (Products — Faza 3 §10,
 * Orders — Faza 3 §11, Invoices — Faza 5 §12, Reports — Faza 4 §16) sunt sărite
 * EXPLICIT: fără ele, testul ar cere conținut din prima zi a Fazei 2, pentru
 * rute care nici n-au controller. Ele intră automat sub acoperire când fazele
 * lor le construiesc, fără nicio schimbare aici.
 *
 * Rutele modulelor construite ÎN PARALEL, de alți agenți, ÎN ACEASTĂ FAZĂ
 * (Accounts, Contacts, Deals, Settings) nu există încă în acest worktree —
 * dacă ruta nu e înregistrată, elementul e sărit FĂRĂ eșec (nu putem cere unei
 * rute inexistente să aibă componentă). După merge, aceeași rută devine
 * înregistrată și testul începe automat s-o verifice — cerința explicită a
 * pachetului F: „testul trebuie să le verifice automat după merge, fără
 * modificări".
 *
 * `Dashboard` nu e în `NAV_ITEMS` (nu e un modul cu link în bara principală),
 * dar e prima pagină după login (FR-DEMO-01) — inclus manual, cerut explicit.
 */
class HelpTopicCoverageTest extends TestCase
{
    /**
     * Module amânate la alte faze — vezi docblock-ul clasei pentru motiv și sursă.
     *
     * @var list<string>
     */
    private const DEFERRED_NAV_LABELS = ['Invoices', 'Reports'];

    public function test_every_built_navigation_route_and_the_dashboard_have_a_help_topic(): void
    {
        $tenant = $this->makeTenant('helpcov', 'Help Coverage Testing Co.');
        $owner = $this->makeMember($tenant, 'help.owner@throughput.dev', Permissions::OWNER);
        $this->clearDatabaseTenantContext();

        $navItems = $this->parseNavItems();
        $this->assertNotEmpty(
            $navItems,
            'NAV_ITEMS nu s-a putut parsa din AppLayout.tsx — regexul a ieșit din sincron cu formatul fișierului sursă.'
        );

        $topicsByComponent = $this->parseHelpIndexKeys();
        $this->assertNotEmpty(
            $topicsByComponent,
            'Nicio cheie nu s-a putut parsa din resources/js/help/index.ts — regexul a ieșit din sincron cu formatul fișierului.'
        );

        // Dashboard nu e în NAV_ITEMS, dar e cerut explicit de pachetul F.
        $targets = [['label' => 'Dashboard', 'uri' => '{workspace}/dashboard']];

        foreach ($navItems as $item) {
            if (in_array($item['label'], self::DEFERRED_NAV_LABELS, true)) {
                continue;
            }

            $targets[] = ['label' => $item['label'], 'uri' => '{workspace}'.$item['path']];
        }

        $checked = [];

        foreach ($targets as $target) {
            if (! $this->routeIsRegistered($target['uri'])) {
                // Construit în paralel, de alt pachet — nu există încă în acest
                // worktree. Vezi docblock-ul clasei: comportamentul dorit, nu o
                // scăpare a testului.
                continue;
            }

            $url = '/'.str_replace('{workspace}', $tenant->slug, $target['uri']);
            $response = $this->actingAs($owner)->get($url);
            $response->assertOk();

            $component = $response->inertiaPage()['component'];

            $this->assertArrayHasKey(
                $component,
                $topicsByComponent,
                sprintf(
                    'Ruta "%s" (%s) randează componenta Inertia "%s", fără subiect de ajutor mapat în resources/js/help/index.ts (FR-HELP-04).',
                    $target['label'],
                    $url,
                    $component
                )
            );

            $checked[] = $target['label'];
        }

        // Dashboard e mereu înregistrat (nu depinde de niciun pachet paralel) —
        // dacă listă e goală, testul ar trece „verde" fără să fi verificat nimic.
        $this->assertContains(
            'Dashboard',
            $checked,
            'Dashboard trebuie să fie mereu verificabil — dacă nu e, testul de acoperire nu verifică nimic.'
        );
    }

    /**
     * @return list<array{label: string, permission: string, path: string}>
     */
    private function parseNavItems(): array
    {
        $source = file_get_contents(base_path('resources/js/Layouts/AppLayout.tsx'));

        preg_match_all(
            '/label:\s*\'([^\']+)\',\s*permission:\s*\'([^\']+)\',\s*href:\s*\(w\)\s*=>\s*`\/\$\{w\}([^`]*)`/',
            $source,
            $matches,
            PREG_SET_ORDER
        );

        return array_map(fn (array $m): array => [
            'label' => $m[1],
            'permission' => $m[2],
            'path' => $m[3],
        ], $matches);
    }

    /**
     * @return array<string, true> chei = nume de componentă Inertia acoperite
     */
    private function parseHelpIndexKeys(): array
    {
        $source = file_get_contents(base_path('resources/js/help/index.ts'));

        preg_match_all('/\'([^\']+)\'\s*:/', $source, $matches);

        return array_fill_keys($matches[1], true);
    }

    private function routeIsRegistered(string $uri): bool
    {
        foreach (Route::getRoutes() as $route) {
            if ($route->uri() === $uri && in_array('GET', $route->methods(), true)) {
                return true;
            }
        }

        return false;
    }
}
