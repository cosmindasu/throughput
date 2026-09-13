/**
 * Baza URL-ului pentru linkurile de ADR din blocul „How it's built" (FR-HELP-02 pct.
 * 4) — o SINGURĂ constantă, cerută explicit, ca o schimbare de vizibilitate a
 * repo-ului să fie o modificare într-un singur loc, nu una per subiect.
 *
 * SEMNALAT (raportul pachetului F): repo-ul de cod e PRIVAT până la audit
 * (`memory/repo-layout-si-vizibilitate.md`), deci acest link nu răspunde public
 * pentru un vizitator anonim al demo-ului înainte de acel moment — rămâne corect
 * pentru audiența secundară din specs.md §1.5 (recrutor/CTO cu acces la repo).
 * Verifică/actualizează această constantă când repo-ul devine public.
 */
export const ADR_REPOSITORY_URL = 'https://github.com/cosmindasu/throughput/blob/main/docs/adr';

export function adrUrl(id: string, slug: string): string {
    return `${ADR_REPOSITORY_URL}/${id}-${slug}.md`;
}
