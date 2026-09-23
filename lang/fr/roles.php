<?php

/**
 * Perechea franceză a lui `lang/en/roles.php` — vezi acolo pentru raționamentul complet
 * (identificatorul rămâne în `App\Support\Permissions`, aici se traduce doar afișarea;
 * I18N-10 — de ce fișierul poartă doar DOUĂ din cele patru roluri ale §7.4).
 *
 * Alegerile de termen, scrise ca atare ca să poată fi contestate de un nativ la revizia
 * proprietarului (ADR-022: „traducerea franceză e scrisă de asistent și revizuită de
 * proprietar"):
 *
 *   - `owner` → **Propriétaire**. Neambiguu ca rol. ATENȚIE la capcana pe care o creează:
 *     aplicația folosește „propriétaire" și cu sensul de PROPRIETAR AL ÎNREGISTRĂRII
 *     (`accounts.owner_id`, „Réattribuer le propriétaire" din bara de selecție în masă),
 *     care NU e rolul RBAC. Cele două sensuri coexistă și în engleză („record owner" vs
 *     rolul „Owner"), deci franceza nu introduce o ambiguitate nouă — dar o traducere care
 *     ar schimba unul dintre sensuri trebuie să nu-l atingă pe celălalt.
 *   - `manager` → **Gestionnaire**. Preferat lui „Responsable", care în franceza de business
 *     sugerează mai degrabă un superior ierarhic decât un nivel de acces.
 *
 * Traducerile pentru `agent`/`viewer` (fostele „Commercial"/„Observateur") rămân doar în
 * `resources/js/locales/fr/roles.json` — catalogul separat pentru ecranele i18next, neatins
 * de ștergerea de-aici.
 */

return [

    'owner' => 'Propriétaire',
    'manager' => 'Gestionnaire',

];
