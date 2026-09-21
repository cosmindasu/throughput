<?php

/**
 * Perechea franceză a lui `lang/en/auth.php` — citește docblock-ul de acolo pentru de ce
 * fișierul a trebuit publicat din framework și ce trebuie făcut la un upgrade de Laravel.
 *
 * NATIV: cele trei mesaje sunt formulările consacrate ale ecosistemului francez Laravel, nu
 * traduceri libere — un utilizator francofon le-a mai văzut în alte aplicații, ceea ce e un
 * avantaj aici, nu o lipsă de originalitate.
 *
 * `:seconds` rămâne în secunde, ca în engleză. `LoginRequest::ensureIsNotRateLimited()` mai
 * trimite și un `:minutes` (`(int) ceil($seconds / 60)`), neutilizat de ȘIRUL implicit al
 * framework-ului nici în engleză — un înlocuitor nefolosit e ignorat tăcut de Laravel, deci
 * fraza franceză nu e obligată să-l consume ca să rămână corectă.
 */

return [

    'failed' => 'Ces identifiants ne correspondent pas à nos enregistrements.',
    'password' => 'Le mot de passe fourni est incorrect.',
    'throttle' => 'Trop de tentatives de connexion. Veuillez réessayer dans :seconds secondes.',

];
