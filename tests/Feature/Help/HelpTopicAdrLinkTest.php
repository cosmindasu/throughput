<?php

namespace Tests\Feature\Help;

use Tests\TestCase;

/**
 * Garda blocurilor `adr` din subiectele de ajutor (FR-HELP-02 pct. 4).
 *
 * De ce există: până în Faza 6, `title` era o PARAFRAZĂ a titlului real — justificată
 * la scriere prin „documentul original e în română". De la `766e2ee` ADR-urile SUNT în
 * engleză, deci justificarea a dispărut, dar parafrazele au rămas și au divergat:
 * ADR-013 apărea sub patru formulări diferite în patru subiecte, ADR-009 și ADR-004
 * căpătaseră clauze inventate („for scheduled report delivery", „applied here to
 * deal-stage history"), iar ADR-010 promitea curierate „pluggable" acolo unde decizia
 * reală spune „două, selectabile per tenant, plus unul demo" — adică exact opusul a ce
 * spune propriul subiect. Niciun test nu putea vedea diferența: linkul răspundea, forma
 * obiectului era validă, TypeScript-ul compila.
 *
 * Parsare regex a fișierelor `.ts`, ca în `HelpTopicCoverageTest` și din același motiv:
 * Pest rulează în PHP și nu poate `import` un modul TypeScript. Testul nu atinge baza
 * de date și nu face cereri HTTP — e o verificare de consistență între două fișiere
 * sursă.
 *
 * Normalizare deliberată înainte de comparație: se scot backtick-urile (titlul real al
 * lui ADR-007 conține `owen-it/laravel-auditing`, iar al lui ADR-008 `/api/v1/...`;
 * afișate în panou ar apărea literal) și se colapsează spațiile albe. Restul — cuvinte,
 * ordine, punctuație — trebuie să fie identic.
 */
class HelpTopicAdrLinkTest extends TestCase
{
    /** Subiectele au voie să n-aibă bloc `adr` (câmp opțional), dar sub acest prag regexul a ieșit din sincron. */
    private const MINIMUM_EXPECTED_BLOCKS = 15;

    public function test_every_help_topic_adr_block_matches_the_real_adr_document(): void
    {
        $blocks = $this->parseAdrBlocks();

        $this->assertGreaterThanOrEqual(
            self::MINIMUM_EXPECTED_BLOCKS,
            count($blocks),
            'Prea puține blocuri `adr` parsate din resources/js/help/topics/ — regexul a ieșit din sincron cu formatul fișierelor sursă.'
        );

        foreach ($blocks as $block) {
            $path = base_path("docs/adr/{$block['id']}-{$block['slug']}.md");

            $this->assertFileExists(
                $path,
                sprintf(
                    '%s: `adrUrl(\'%s\', \'%s\')` trimite către un ADR inexistent.',
                    $block['file'],
                    $block['id'],
                    $block['slug']
                )
            );

            $this->assertSame(
                $block['id'],
                $block['urlId'],
                sprintf(
                    '%s: câmpul `id` e "%s", dar `adrUrl()` primește "%s" — linkul duce în altă parte decât spune titlul.',
                    $block['file'],
                    $block['id'],
                    $block['urlId']
                )
            );

            $this->assertSame(
                $this->normalise($this->headingOf($path)),
                $this->normalise($block['title']),
                sprintf(
                    '%s: `title` nu e titlul real al lui %s. Copiază titlul din docs/adr/, fără prefixul "%s: " — nu-l reformula (vezi docblock-ul lui HelpTopicAdr din resources/js/help/types.ts).',
                    $block['file'],
                    $block['id'],
                    $block['id']
                )
            );
        }
    }

    /**
     * @return list<array{file: string, id: string, title: string, urlId: string, slug: string}>
     */
    private function parseAdrBlocks(): array
    {
        $blocks = [];

        foreach (glob(base_path('resources/js/help/topics/*.ts')) as $file) {
            preg_match_all(
                '/adr:\s*\{\s*id:\s*\'([^\']+)\',\s*title:\s*\'((?:[^\'\\\\]|\\\\.)*)\',\s*url:\s*adrUrl\(\s*\'([^\']+)\'\s*,\s*\'([^\']+)\'\s*\)\s*,?\s*\}/',
                file_get_contents($file),
                $matches,
                PREG_SET_ORDER
            );

            foreach ($matches as $match) {
                $blocks[] = [
                    'file' => basename($file),
                    'id' => $match[1],
                    // Apostrofurile din titlu sunt escapate în sursa TypeScript.
                    'title' => str_replace("\\'", "'", $match[2]),
                    'urlId' => $match[3],
                    'slug' => $match[4],
                ];
            }
        }

        return $blocks;
    }

    /** Titlul ADR-ului, fără prefixul „ADR-00X: ". */
    private function headingOf(string $path): string
    {
        preg_match('/^#\s*ADR-\d+:\s*(.+)$/m', file_get_contents($path), $match);

        return $match[1] ?? '';
    }

    private function normalise(string $value): string
    {
        return trim(preg_replace('/\s+/', ' ', str_replace('`', '', $value)));
    }
}
