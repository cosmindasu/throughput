<?php

/**
 * Numele celor patru roluri RBAC (§7.4), ca etichete de interfață.
 *
 * Sursa de adevăr pentru IDENTIFICATOR rămâne `App\Support\Permissions` (`Permissions::OWNER`
 * etc.) — acolo sunt constantele cu care lucrează Spatie Permission, Policies-urile și
 * coloana `model_has_roles.role_id`. Fișierul ăsta traduce DOAR afișarea; nimic din logica de
 * autorizare nu-l citește, iar o traducere nouă nu poate rupe o verificare de permisiune.
 *
 * Cheile sunt identificatorul cu minuscule, nu constanta TitleCase, ca să poată fi compuse
 * direct dintr-un slug de rută (`/login/demo/owner`) fără o a doua hartă de normalizare.
 *
 * **Există și în `resources/js/locales/{en,fr}/roles.json`**, cu exact aceleași patru chei —
 * două straturi de randare diferite (Laravel pentru e-mail/PDF, i18next pentru ecrane),
 * aceeași convenție ca la restul cataloagelor. `php artisan i18n:coverage` verifică simetria
 * en↔fr pe fiecare strat în parte, dar NU între straturi: dacă schimbi un nume aici,
 * schimbă-l și acolo.
 *
 * Adăugat la Valul 3 al Lotului I18N, ca decizie a proprietarului (2026-09-21) — până atunci
 * rolurile rămâneau în engleză peste tot, cu nota din `lang/fr/mail.php` care explica de ce:
 * lipsea exact fișierul ăsta.
 */

return [

    'owner' => 'Owner',
    'manager' => 'Manager',
    'agent' => 'Agent',
    'viewer' => 'Viewer',

];
