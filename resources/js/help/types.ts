/**
 * Forma unui subiect de ajutor — FR-HELP-02, structură fixă în patru părți, în
 * ordinea din specificație.
 *
 * **Împărțit în două de Valul 4 al Lotului I18N (ADR-022, FR-I18N-02).** Până atunci,
 * un singur obiect `HelpTopic` per fișier `resources/js/help/topics/` ținea și
 * structura, și textul englez. Textul a plecat în cataloage per limbă
 * (`resources/js/locales/{en,fr}/help.json`), fiindcă acolo — și NUMAI acolo —
 * `php artisan i18n:coverage` îl vede: comanda compară recursiv `*.json` sub
 * `resources/js/locales/{en,fr}/`, deci subiectele intră sub gardă fără un al
 * patrulea strat scris special pentru ele. FR-I18N-02 cere explicit „panoul de ajutor
 * cu toate subiectele lui" în domeniul acoperit; un mecanism paralel, care ar fi cerut
 * parsarea TypeScript-ului din PHP, ar fi fost exact fragilitatea pe care
 * `I18nCoverage` o respinge argumentat pentru vederile de eroare.
 *
 * `HelpTopic` (forma consumată de panou) rămâne NESCHIMBAT — cerință explicită a
 * planului („păstrând tipul `HelpTopic` existent"). Ce se schimbă e de unde se umple:
 * `HelpTopicDefinition` (structura, în `.ts`) + catalogul limbii active
 * (textul, în JSON) → `useHelpTopic()` le compune.
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
     *
     * **NU se traduce, în nicio limbă** (Valul 4, Lot I18N): e citatul unui document
     * care există într-o singură limbă, nu o etichetă de interfață. De aceea blocul
     * `adr` stă pe `HelpTopicDefinition`, alături de `id`, nu în catalogul per limbă —
     * o traducere franceză a titlului ar rupe chiar egalitatea pe care testul o ține,
     * iar linkul ar promite un document care nu există.
     */
    title: string;
    /** Construit din `ADR_REPOSITORY_URL`, o singură constantă — vezi `help/adr.ts`. */
    url: string;
}

/**
 * Partea INDEPENDENTĂ DE LIMBĂ a unui subiect: ce nu se traduce niciodată. Un fișier
 * per subiect (`resources/js/help/topics/`) exportă un obiect din această formă;
 * `index.ts` face harta componentă → definiție.
 *
 * Docblock-ul fiecărui fișier rămâne acolo unde era, deliberat: e reconcilierea cu
 * codul ecranului (ce controller, ce Action, ce constantă de config), adică motivul
 * pentru care textul spune ce spune. Mutat într-un JSON, s-ar fi pierdut.
 */
export interface HelpTopicDefinition {
    /**
     * Identificator stabil (kebab-case), folosit ca `id` ARIA în panou ȘI ca cheie în
     * catalog (`help:topics.<id>.*`). Nu e numele componentei Inertia — mai multe
     * componente pot împărți un subiect (ex. `Accounts/Create` și `Accounts/Edit`),
     * harta din `index.ts` face legătura.
     */
    id: string;
    /** Absent când nu există o decizie de nivel de arhitectură dedicată acestui ecran. */
    adr?: HelpTopicAdr;
}

export interface HelpTopic {
    /** Vezi `HelpTopicDefinition.id`. */
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
        adr?: HelpTopicAdr;
    };
}
