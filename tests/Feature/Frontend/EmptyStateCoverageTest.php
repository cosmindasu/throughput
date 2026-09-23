<?php

namespace Tests\Feature\Frontend;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * FR-DEMO-02 — fiecare listă goală are un CTA contextual, nu un ecran gol
 * (`resources/js/Components/EmptyState.tsx`: „«No deals match this filter. [Clear
 * filters]», nu un ecran alb"). `EmptyState` e folosit pe larg, dar nimic nu verifică
 * sistematic per-ecran — pachetul D cere exact acoperirea care lipsește.
 *
 * Forma reală a propurilor componentei (citită înainte de a decide ce se asertează):
 *
 *     interface EmptyStateProps { message: string; action?: ReactNode; }
 *
 * `message` explică DE CE lista e goală; `action` e CTA-ul propriu-zis — un buton, un
 * link sau un text de îndrumare. Un ecran cu doar `message` (fără `action`) e un mesaj,
 * nu un CTA: FR-DEMO-02 cere al doilea, deci testul verifică prezența propului `action`
 * în JSX, nu doar importul componentei (import fără folosire, sau folosire fără `action`,
 * ar trece tautologic un test mai slab).
 *
 * Sursă verificabilă aleasă, aceeași logică ca `HelpTopicCoverageTest` (parsare de sursă
 * TypeScript — Pest rulează în PHP, fără test runner JS în stack). Enumerarea paginilor e
 * DINAMICĂ (`File::allFiles` pe `resources/js/Pages/`, filtrat pe `Index.tsx`), nu o listă
 * hardcodată: orice pagină de listă nouă intră automat în domeniul testului. Fiecare
 * fișier descoperit TREBUIE să fie clasificat explicit mai jos — inclus (cu CTA verificat)
 * sau exclus (cu motiv scris în cod, cerut explicit de brief-ul pachetului D: „nu-l scoate
 * tăcut din listă") — altfel testul pică cerând clasificarea, nu trece tăcut peste el.
 *
 * Verificat că pică la o regresie reală: scos temporar propul `action` din
 * `Pages/Accounts/Index.tsx` (înlocuit blocul `action={...}` cu nimic) → testul a picat cu
 * mesajul de mai jos, apoi codul a fost pus la loc (vezi raportul livrat — codul de
 * producție nu se atinge de acest pachet).
 */
class EmptyStateCoverageTest extends TestCase
{
    /**
     * Pagini de listă unde `EmptyState` trebuie folosit CU propul `action` (CTA real).
     *
     * Toate opt sunt liste de înregistrări create/gestionate de utilizator (conturi,
     * contacte, deals, importuri, comenzi, etape de pipeline, produse, rapoarte) — o listă
     * goală aici înseamnă mereu „nu există încă nimic", cu o acțiune evidentă de oferit:
     * creează primul rând, sau curăță filtrul care a golit lista.
     *
     * @var list<string>
     */
    private const PAGES_REQUIRING_ACTION = [
        'Accounts/Index.tsx',
        'Contacts/Index.tsx',
        'Deals/Index.tsx',
        'Imports/Index.tsx',
        'Orders/Index.tsx',
        'Pipeline/Index.tsx',
        'Products/Index.tsx',
        'Reports/Index.tsx',
    ];

    /**
     * Pagini de listă excluse din cerința de CTA, cu MOTIVUL explicit — nu o scăpare, o
     * decizie. Fiecare motiv e verificabil citind pagina însăși.
     *
     * @var array<string, string>
     */
    private const EXCLUDED_PAGES_WITH_REASON = [
        // Jurnal de audit read-only (FR-AUD-03, §17.3): o intrare de activitate nu se
        // „creează" manual — ea există sau nu există. Singura acțiune utilă la o listă
        // goală ar fi golirea filtrului, dar `EmptyState` de aici nu distinge deja
        // (spre deosebire de Accounts/Products, unde `hasFilters` alege textul) — nu
        // există CTA de adăugat fără să inventăm un buton „Clear filters" care lipsește
        // și din backend-ul filtrului curent (`?action=&userId=&from=&to=`, fără cale de
        // resetare dedicată în UI azi).
        'Activity/Index.tsx' => 'jurnal de audit read-only — nicio acțiune de „creare" nu există pentru o intrare de activitate.',

        // Facturile se generează AUTOMAT din comenzi (specs.md §12.1) — nu există flux de
        // „creează factură" în UI. Singurele acțiuni ale ecranului (export CSV/PDF, în
        // `PageHeader`) sunt irelevante pe o listă goală (nimic de exportat) și nu sunt
        // CTA-uri de populare a listei.
        'Invoices/Index.tsx' => 'facturile se generează automat din comenzi — nu există flux de creare manuală de oferit ca CTA.',

        // CTA-ul există pe ecran — `CreateTokenForm` (linia `{can.create && <CreateTokenForm
        // .../>}`) e randat DEASUPRA tabelului, necondiționat de starea listei, nu în
        // interiorul lui `EmptyState`. Utilizatorul vede formularul de creare indiferent
        // dacă lista e goală sau nu, deci cerința FR-DEMO-02 („nu un ecran gol") e deja
        // respectată — doar nu prin propul `action` al acestei componente.
        'Settings/ApiTokens/Index.tsx' => 'formularul de creare (CreateTokenForm) e mereu vizibil deasupra listei, nu condiționat prin action-ul EmptyState.',

        // Jurnal read-only al emailurilor trimise de SISTEM (facturi, invitații) — nimic nu
        // se „creează" manual de pe acest ecran; un email apare aici ca EFECT al altei
        // acțiuni (emitere factură, invitare membru), petrecută în alt ecran.
        'Settings/SentEmails/Index.tsx' => 'jurnal read-only al emailurilor trimise de sistem — nimic de creat manual de aici.',

        // Jurnal de monitorizare read-only al evenimentelor webhook primite de la Stripe —
        // aceleași motive ca Activity/SentEmails: nimic nu se creează manual.
        'Settings/WebhookHealth/Index.tsx' => 'jurnal de monitorizare read-only al evenimentelor webhook — nimic de creat manual de aici.',

        // Vederea se populează AUTOMAT când un membru cu înregistrări deschise e dezactivat
        // (FR-TEN-05, ADR-011) — nu există flux de „creează un unassigned". Reatribuirea
        // (butonul `reassign`) se aplică pe o listă NEGOALĂ; pe o listă goală n-are ce
        // reatribui.
        'Unassigned/Index.tsx' => 'lista se populează automat la dezactivarea unui membru cu înregistrări deschise — nimic de creat manual.',

        // Ecran de status/sumar de abonament (FR-BILL-01), Owner-only — nu e o listă de
        // înregistrări gestionate de utilizator. Sub-lista de facturi de pe acest ecran
        // (istoric Stripe) folosește un `<p>` inline la starea goală, nu `EmptyState`, iar
        // facturile Stripe se generează automat — același motiv ca `Invoices/Index.tsx`.
        'Settings/Billing/Index.tsx' => 'ecran de sumar de abonament, nu o listă de înregistrări gestionate de utilizator; sub-lista de facturi e generată automat de Stripe.',

        // Jurnal de cereri de export GDPR (FR-GDPR-01) — butonul „Request export" din
        // `PageHeader` e mereu vizibil (necondiționat de starea listei), la fel ca la
        // ApiTokens: CTA-ul există pe ecran, doar nu prin `EmptyState`.
        'Settings/DataExport/Index.tsx' => 'butonul „Request export" din PageHeader e mereu vizibil, necondiționat de starea listei — CTA-ul există, doar nu prin EmptyState.',

        // Hub de navigație (carduri fixe către secțiunile de Settings, filtrate pe
        // permisiune) — NU e o listă de date dintr-un tabel al tenantului; secțiunile
        // existente nu pot fi „create" sau lipsi din cauza absenței datelor.
        'Settings/Index.tsx' => 'hub de navigație cu secțiuni fixe filtrate pe permisiune, nu o listă de înregistrări ale tenantului.',

        // Lista de membri nu poate fi goală NICIODATĂ — utilizatorul care o vede e el
        // însuși membru activ al tenantului (altfel n-ar avea acces la `/settings/members`).
        'Settings/Members/Index.tsx' => 'lista de membri nu poate fi goală — utilizatorul care o vede e el însuși membru activ al tenantului.',

        // Set FIX de furnizori de curierat cunoscuți de aplicație (Shippo, EasyPost, demo —
        // ADR-010), nu înregistrări create de utilizator — cardurile există mereu, indiferent
        // dacă tenantul a configurat vreunul.
        'Settings/Shipping/Index.tsx' => 'set fix de furnizori de curierat cunoscuți de aplicație, nu înregistrări create de utilizator — lista nu poate fi goală.',
    ];

    public function test_every_list_page_has_a_contextual_empty_state_or_an_explicit_exclusion(): void
    {
        $discovered = $this->discoverIndexPages();

        $this->assertNotEmpty(
            $discovered,
            'Niciun Index.tsx găsit sub resources/js/Pages/ — scanarea a ieșit din sincron cu structura de directoare.'
        );

        $classified = [...self::PAGES_REQUIRING_ACTION, ...array_keys(self::EXCLUDED_PAGES_WITH_REASON)];

        // Orice pagină nouă de listă (Index.tsx) trebuie clasificată EXPLICIT mai sus —
        // inclusă (CTA verificat) sau exclusă (motiv scris în cod). Fără această gardă, o
        // pagină nouă ar trece pe lângă test în tăcere, exact anti-tiparul semnalat în
        // brief: „excluderile trebuie să fie explicite și motivate, nu tăcute".
        sort($discovered);
        sort($classified);
        $this->assertSame(
            $discovered,
            $classified,
            "Pagini Index.tsx neclasificate în EmptyStateCoverageTest (adaugă-le în PAGES_REQUIRING_ACTION sau în EXCLUDED_PAGES_WITH_REASON, cu motiv):\n"
            .implode("\n", array_diff($discovered, $classified))
            ."\n\nIntrări clasificate care nu mai există pe disc (curăță-le din listă):\n"
            .implode("\n", array_diff($classified, $discovered))
        );

        $checked = [];

        foreach (self::PAGES_REQUIRING_ACTION as $page) {
            $source = file_get_contents(base_path('resources/js/Pages/'.$page));

            $this->assertStringContainsString(
                "from '@/Components/EmptyState'",
                $source,
                "FR-DEMO-02: {$page} nu importă EmptyState."
            );

            $this->assertMatchesRegularExpression(
                '/<EmptyState\b([\s\S]*?)\/>/',
                $source,
                "FR-DEMO-02: {$page} importă EmptyState dar nu-l randează (nicio invocare <EmptyState ... /> găsită)."
            );

            preg_match('/<EmptyState\b([\s\S]*?)\/>/', $source, $match);

            $this->assertMatchesRegularExpression(
                '/\baction\s*=/',
                $match[1],
                "FR-DEMO-02: {$page} randează <EmptyState /> fără propul `action` — un mesaj fără CTA nu satisface cerința (vezi EmptyState.tsx)."
            );

            $checked[] = $page;
        }

        // Gardă minimă, ca în `HelpTopicCoverageTest`: dacă lista de mai sus ar deveni goală
        // printr-o modificare greșită, testul n-ar verifica nimic și tot ar trece „verde".
        $this->assertNotEmpty($checked, 'PAGES_REQUIRING_ACTION e goală — testul nu verifică nimic.');
    }

    /**
     * @return list<string> căi relative la resources/js/Pages/, cu separator `/`
     */
    private function discoverIndexPages(): array
    {
        $base = base_path('resources/js/Pages');

        $paths = collect(File::allFiles($base))
            ->filter(fn ($file) => $file->getFilename() === 'Index.tsx')
            ->map(fn ($file) => str_replace('\\', '/', ltrim(str_replace($base, '', $file->getPathname()), '/\\')))
            ->values()
            ->all();

        sort($paths);

        return $paths;
    }
}
