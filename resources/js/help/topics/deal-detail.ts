import type { HelpTopicDefinition } from '@/help/types';

/**
 * `Deals/Show`, `Deals/Create`, `Deals/Edit` — specs.md §9.2/9.4/9.5 (FR-DEAL-03,
 * BR-DEAL-02, US-DEAL-01).
 *
 * Reconciliat cu codul la 2026-09-13: `Pages/Deals/Show.tsx` („Move to stage…", „Edit", „Delete",
 * secțiunea „Stage history"), `DealPolicy` (Agent doar pe deal-urile proprii, `changeOwner` doar
 * Owner/Manager), `StoreDealRequest` (contactul principal trebuie să fie al contului ales),
 * `CreateDealAction` (prima etapă, owner = creatorul), `MoveDealStageAction`, `LostReasonDialog`.
 *
 * Câmpul „Account" cu căutare (`AccountCombobox`), în Create ȘI în Edit, e schimbarea făcută în
 * paralel de alt agent: precompletat din `?account=` când se pleacă din pagina contului, gol
 * fără el; la schimbarea contului se golește contactul principal care nu aparține noului cont;
 * etapa, owner-ul și istoricul de etape rămân neschimbate.
 */
const dealDetail: HelpTopicDefinition = {
    id: 'deal-detail',
};

export default dealDetail;
