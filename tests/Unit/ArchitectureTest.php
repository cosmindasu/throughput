<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Reguli pe care code review-ul le-ar prinde în zilele bune și le-ar rata în celelalte.
 *
 * Nu extinde `Tests\TestCase`: nu are nevoie de bază de date, iar o suită arhitecturală
 * care pornește PostgreSQL ca să citească fișiere ar fi tocmai genul de cost inutil pe care
 * îl evită tot restul proiectului.
 */
class ArchitectureTest extends TestCase
{
    public function test_the_eloquent_tenant_scope_is_bypassed_in_exactly_one_place(): void
    {
        $offenders = [];

        foreach ($this->phpFilesIn(__DIR__.'/../../app') as $file) {
            $code = $this->codeWithoutComments($file);

            // Listă ALBĂ, nu neagră: orice `withoutGlobalScope(...)` numără ca ofensator, cu o
            // singură excepție, `NotAnonymizedContactScope::class` (specs.md §20.5, contactul
            // principal anonimizat afișat pe deal). O listă neagră pe `TenantScope::class` ar fi
            // lăsat să treacă forma cu namespace complet sau un alias de import.
            // `withoutGlobalScopes()` le scoate pe TOATE, deci și pe cel de tenant.
            $bypassesTenantScope = str_contains($code, 'withoutGlobalScopes(');

            preg_match_all('/withoutGlobalScope\s*\(([^)]*)\)/', $code, $matches);

            foreach ($matches[1] as $argument) {
                if (trim($argument) !== 'NotAnonymizedContactScope::class') {
                    $bypassesTenantScope = true;
                }
            }

            if ($bypassesTenantScope) {
                $offenders[] = $this->relative($file);
            }
        }

        // Singurul loc legitim e `Membership::forCurrentUserAcrossTenants()`, pentru
        // comutatorul de workspace (ADR-014, pct. 2) — o interogare cross-tenant prin
        // natura ei. Orice al doilea loc are nevoie de un ADR, nu de un commit.
        $this->assertSame(
            ['app/Models/Membership.php'],
            $offenders,
            'Ocolirea global scope-ului de tenant e permisă doar în Membership::forCurrentUserAcrossTenants().'
        );
    }

    public function test_env_is_read_only_inside_config_files(): void
    {
        $offenders = [];

        foreach ($this->phpFilesIn(__DIR__.'/../../app') as $file) {
            if (preg_match('/(?<![>\w:])env\s*\(/', $this->codeWithoutComments($file))) {
                $offenders[] = $this->relative($file);
            }
        }

        // După `config:cache` (pe care entrypoint-ul de producție îl rulează), `env()`
        // întoarce implicitul. Un `env('DEMO_MODE')` în cod s-ar fi stins tăcut exact în
        // singurul mediu unde contează — vezi comentariul din config/throughput.php.
        $this->assertSame([], $offenders, 'Citește prin config(), nu prin env(), în afara fișierelor de configurare.');
    }

    public function test_tenant_context_is_set_through_the_single_gate(): void
    {
        $offenders = [];

        foreach ($this->phpFilesIn(__DIR__.'/../../app') as $file) {
            $relative = $this->relative($file);

            if ($relative === 'app/Services/Tenancy/TenantContext.php') {
                continue;
            }

            if (str_contains($this->codeWithoutComments($file), 'set_config')) {
                $offenders[] = $relative;
            }
        }

        // ADR-014, pct. 3: o singură poartă. Trei locuri care setează contextul înseamnă
        // trei feluri de a-l seta greșit — și exact așa arăta codul înainte de ADR.
        $this->assertSame([], $offenders, 'Contextul se setează doar prin TenantContext.');
    }

    /**
     * Codul fără comentarii.
     *
     * Prima variantă a acestor verificări citea fișierul brut și pica pe COMENTARIILE care
     * explicau tocmai regula („citit din config, niciodată din env()") — un test care
     * pedepsește documentarea regulii pe care o impune nu rezistă nici o săptămână.
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
        $root = realpath(__DIR__.'/../../').'/';

        return str_replace($root, '', $file->getRealPath());
    }
}
