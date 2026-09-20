/**
 * Forma unui subiect de ajutor — FR-HELP-02, structură fixă în patru părți, în
 * ordinea din specificație. Un fișier per subiect (`resources/js/help/topics/`)
 * exportă un obiect din această formă; `index.ts` face harta componentă → subiect.
 *
 * Conținut static, versionat în repo (FR-HELP-03) — niciun câmp de aici nu vine
 * din bază de date, deci nu are nevoie de RLS, seed sau reset zilnic.
 */

export interface HelpTopicAdr {
    /** Ex: „ADR-003". */
    id: string;
    /**
     * Titlul ADR-ului, EXACT cum e scris în `docs/adr/`, fără prefixul „ADR-00X: "
     * și fără backticks. NU o parafrază: ADR-urile sunt în engleză de la `766e2ee`,
     * deci un titlu rescris aici nu mai traduce nimic — doar divergea de documentul
     * pe care îl deschide cititorul (ADR-013 ajunsese sub patru formulări diferite,
     * iar ADR-010 promitea curierate „pluggable" acolo unde decizia spune „două, plus
     * unul demo"). `HelpTopicAdrLinkTest` ține egalitatea.
     */
    title: string;
    /** Construit din `ADR_REPOSITORY_URL`, o singură constantă — vezi `help/adr.ts`. */
    url: string;
}

export interface HelpTopic {
    /**
     * Identificator stabil (kebab-case), folosit ca `id` ARIA în panou. Nu e numele
     * componentei Inertia — mai multe componente pot împărți un subiect (ex.
     * `Accounts/Create` și `Accounts/Edit`), harta din `index.ts` face legătura.
     */
    id: string;
    /** Titlul afișat în capul panoului. */
    title: string;
    /** FR-HELP-02 pct. 1 — „ce e pagina asta", în termenii utilizatorului, nu ai sistemului. */
    whatIsThis: string;
    /** FR-HELP-02 pct. 2 — 3-5 acțiuni REALE, fiecare numită exact ca butonul din UI. */
    whatCanYouDo: string[];
    /** FR-HELP-02 pct. 3 — regulile de business EFECTIVE ale ecranului, nu generalități. */
    rules: string[];
    /** FR-HELP-02 pct. 4 — bloc pliat, închis implicit; audiența secundară din specs.md §1.5. */
    howItsBuilt: {
        summary: string;
        /** Absent când nu există o decizie de nivel de arhitectură dedicată acestui ecran. */
        adr?: HelpTopicAdr;
    };
}
