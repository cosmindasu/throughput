import type { HelpTopicDefinition } from '@/help/types';

/**
 * `Settings/Index` — specs.md §7.1/7.3/7.4 (matricea de permisiuni), hub-ul din care pornesc
 * Preferences, Pipeline, Members, Billing etc.
 *
 * Reconciliat cu codul la 2026-09-20, la integrarea valului 2 al Fazei 5: cardurile din
 * `Pages/Settings/Index.tsx` (`SECTIONS`, butonul „Open") și `can` din
 * `SettingsController::index()`, calculat din `App\Support\Permissions::forRoles()`.
 *
 * Textul de dinainte descria Billing și API Tokens ca fiind încă „placeholders" cu insignă
 * „Coming in a later phase" — adevărat când a fost scris, fals de la valul 1 încoace. BR-HELP-04
 * cere ca subiectul să fie contemporan cu ecranul; aici n-a fost, fiindcă ecranul s-a schimbat
 * din alt lot decât cel care ținea textul. De aceea recitirea manualului e un task explicit în
 * Faza 6, nu o presupunere.
 */
const settings: HelpTopicDefinition = {
    id: 'settings',
};

export default settings;
