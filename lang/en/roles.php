<?php

/**
 * Numele rolurilor RBAC (§7.4) CHIAR FOLOSITE de mesaje server-side (Laravel), ca etichete
 * de interfață.
 *
 * Sursa de adevăr pentru IDENTIFICATOR rămâne `App\Support\Permissions` (`Permissions::OWNER`
 * etc.) — acolo sunt constantele cu care lucrează Spatie Permission, Policies-urile și
 * coloana `model_has_roles.role_id`. Fișierul ăsta traduce DOAR afișarea; nimic din logica de
 * autorizare nu-l citește, iar o traducere nouă nu poate rupe o verificare de permisiune.
 *
 * Cheile sunt identificatorul cu minuscule, nu constanta TitleCase, ca să poată fi compuse
 * direct dintr-un slug de rută (`/login/demo/owner`) fără o a doua hartă de normalizare.
 *
 * I18N-10 — `agent` și `viewer` ȘTERSE (2026-09-23): patru roluri există în §7.4, dar acest
 * catalog Laravel poartă DOAR cele pe care le citește un `__('roles.*')` din `app/` —
 * confirmat prin grep pe `app/`, `resources/views`, `routes`, `tests`: doar
 * `MembershipPolicy`, `DemoMode::refusal()` și `MembershipRecordsNeedNewOwnerNotification`
 * interpolează `roles.owner`/`roles.manager` în mesaje despre transferul de proprietate —
 * niciun mesaj server-side nu menționează vreodată un Agent sau un Viewer pe nume. Ecranul
 * de membri (`resources/views/members/mail/invitation.blade.php`) afișează `$roleName` BRUT
 * (identificatorul, nu o traducere), deci nu citește nici el catalogul ăsta.
 *
 * **Cele patru rămân în `resources/js/locales/{en,fr}/roles.json`**, catalog SEPARAT pentru
 * ecranele i18next (selectul de rol, badge-urile din listă) — acela chiar are nevoie de toate
 * patru și NU e atins de ștergerea de aici. `php artisan i18n:coverage` verifică simetria
 * en↔fr pe fiecare strat în parte, dar NU între straturi.
 *
 * Adăugat la Valul 3 al Lotului I18N, ca decizie a proprietarului (2026-09-21) — până atunci
 * rolurile rămâneau în engleză peste tot, cu nota din `lang/fr/mail.php` care explica de ce:
 * lipsea exact fișierul ăsta.
 */

return [

    'owner' => 'Owner',
    'manager' => 'Manager',

];
