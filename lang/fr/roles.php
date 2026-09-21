<?php

/**
 * Perechea franceză a lui `lang/en/roles.php` — vezi acolo pentru raționamentul complet
 * (identificatorul rămâne în `App\Support\Permissions`, aici se traduce doar afișarea).
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
 *   - `agent` → **Commercial**. SINGURA alegere cu adevărat discutabilă din fișier:
 *     „Agent" e cuvânt francez valid și ar fi fost traducerea literală, dar în franceza de
 *     business desemnează mai degrabă un funcționar decât un vânzător. Rolul din §7.4 e
 *     explicit cel de vânzător (lucrează pe conturile și oportunitățile PROPRII), iar
 *     „commercial" e termenul uzual pentru asta. De confirmat.
 *   - `viewer` → **Observateur**. Preferat lui „Lecteur", care sugerează un cititor de
 *     documente; „observateur" e convenția folosită de interfețele francofone pentru un
 *     acces strict read-only.
 */

return [

    'owner' => 'Propriétaire',
    'manager' => 'Gestionnaire',
    'agent' => 'Commercial',
    'viewer' => 'Observateur',

];
