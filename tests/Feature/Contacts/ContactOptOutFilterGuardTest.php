<?php

namespace Tests\Feature\Contacts;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Gardă de arhitectură pentru BR-CRM-02 (specs.md): „`opt_out = true` pe un contact
 * suprimă doar comunicările de marketing VIITOARE (Faza 2) — nu afectează comunicările
 * tranzacționale (confirmări de comandă, facturi)."
 *
 * Nu extinde `Tests\TestCase` — la fel ca `tests/Unit/ArchitectureTest.php`: scanează
 * fișiere, nu are nevoie de bază de date/tenant.
 *
 * **Onestitate despre ce dovedește și ce NU dovedește acest test** (vezi și
 * `ContactOptOutTransactionalCommunicationsTest` din același director, pentru jumătatea
 * de comportament HTTP): azi, în `app/`, NICIUN cod nu citește `opt_out` ca să suprime
 * ceva — verificat prin grep exhaustiv pe tot `app/` la 2026-09-22. Coloana e scrisă
 * (`ContactErasure`, la anonimizare), exportată ca informație (`ContactList`), expusă în
 * resurse (`ContactResource`, `Api\V1\ContactResource`) și acceptată la CRUD
 * (`Store`/`UpdateContactRequest`) — dar niciun expeditor n-o CONSULTĂ ca filtru. Deci
 * jumătatea „suprimă marketingul" a regulii e, la propriu, netestabilă azi (marketingul
 * n-a fost construit). Acest test NU umple acel gol și nu pretinde că-l umple.
 *
 * Ce face acest test: fixează lista de fișiere din `app/` care au voie să menționeze
 * `opt_out` — toate țin de GESTIUNEA contactului însuși, nu de vreo cale tranzacțională.
 * Dacă un commit viitor adaugă un filtru `opt_out` într-un loc nou (`OrderController`,
 * `CreateOrderAction`, un eventual `OrderConfirmationMail`/`InvoiceMail`, sau — mai
 * subtil — un scope Eloquent aplicat implicit pe interogări de Order/Invoice), fișierul
 * acela iese din lista albă și testul pică. Asta prinde momentul în care cineva ÎNCEPE
 * să filtreze tranzacționalul pe `opt_out`, chiar dacă intenția e „doar marketingul" —
 * codul nou tot trebuie revăzut manual, testul doar face imposibil să treacă neobservat.
 * Nu e o dovadă matematică de corectitudine — e o plasă, ca restul suitei arhitecturale.
 */
class ContactOptOutFilterGuardTest extends TestCase
{
    /**
     * Singurele fișiere din `app/` care ating `opt_out` azi — toate despre gestiunea
     * contactului (model, export/afișare, ștergere GDPR, resurse HTTP, validare CRUD),
     * nimic dintr-o cale de Order/Invoice/Deal sau dintr-un expeditor de email.
     *
     * @return list<string>
     */
    private function whitelist(): array
    {
        return [
            'app/Models/Contact.php',
            'app/Support/Lists/ContactList.php',
            'app/Support/Contacts/ContactErasure.php',
            'app/Http/Resources/ContactResource.php',
            'app/Http/Resources/Api/V1/ContactResource.php',
            'app/Http/Requests/Contacts/StoreContactRequest.php',
            'app/Http/Requests/Contacts/UpdateContactRequest.php',
        ];
    }

    public function test_opt_out_is_not_referenced_outside_contact_management_files(): void
    {
        $offenders = [];

        foreach ($this->phpFilesIn(__DIR__.'/../../../app') as $file) {
            $relative = $this->relative($file);

            if (! str_contains($this->codeWithoutComments($file), 'opt_out')) {
                continue;
            }

            if (! in_array($relative, $this->whitelist(), true)) {
                $offenders[] = $relative;
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "Un fișier NOU citește `opt_out` în afara listei albe (gestiunea contactului).\n"
            ."BR-CRM-02: doar comunicările de marketing (Faza 2, inexistentă azi) au voie să-l\n"
            ."consulte ca filtru — verifică dacă fișierul de mai jos aparține unei căi\n"
            ."tranzacționale (comenzi, facturi); dacă da, e exact regresia pe care garda asta\n"
            .'o caută. Dacă e un mecanism de marketing legitim, adaugă-l explicit în lista albă.'."\n"
            .implode("\n", $offenders),
        );
    }

    /**
     * Gardă simetrică: dacă `opt_out` e redenumit sau unul dintre fișierele de mai sus
     * dispare/e golit, lista albă ar deveni tăcut prea permisivă — ar „proteja" un fișier
     * gol, iar testul de mai sus n-ar mai avea nicio șansă să detecteze o migrare reală a
     * regulii. Aici pică vizibil, în loc să lase garda principală oarbă.
     */
    public function test_every_whitelisted_file_still_exists_and_still_mentions_opt_out(): void
    {
        foreach ($this->whitelist() as $relative) {
            $path = __DIR__.'/../../../'.$relative;

            $this->assertFileExists($path, "Fișier din lista albă care nu mai există: {$relative}");
            $this->assertStringContainsString(
                'opt_out',
                $this->codeWithoutComments(new SplFileInfo($path)),
                "Fișierul din lista albă nu mai conține `opt_out`: {$relative} — scoate-l din listă."
            );
        }
    }

    /**
     * Codul fără comentarii — un docblock care EXPLICĂ regula (ca ăsta) nu trebuie să
     * numere ca „referință" (același motiv ca în `ArchitectureTest::codeWithoutComments()`).
     */
    private function codeWithoutComments(SplFileInfo $file): string
    {
        $code = '';

        foreach (token_get_all(file_get_contents($file->getPathname())) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $code .= is_array($token) ? $token[1] : $token;
        }

        return $code;
    }

    /**
     * @return list<SplFileInfo>
     */
    private function phpFilesIn(string $directory): array
    {
        $files = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory)) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file;
            }
        }

        usort($files, fn (SplFileInfo $a, SplFileInfo $b) => strcmp($a->getPathname(), $b->getPathname()));

        return $files;
    }

    private function relative(SplFileInfo $file): string
    {
        $root = realpath(__DIR__.'/../../../').'/';

        return str_replace($root, '', $file->getRealPath());
    }
}
