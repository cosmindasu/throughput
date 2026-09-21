<?php

/**
 * PUBLICAT din framework, VERBATIM — vezi docblock-ul din `lang/en/auth.php` pentru motiv
 * și pentru procedura de upgrade. Array-ul e copie exactă, verificată cu `===`.
 *
 * TOATE cele cinci chei sunt LIVE: `App\Http\Controllers\Web\Auth\NewPasswordController`
 * face `trans($status)` pe rezultatul brut al brokerului, deci oricare din
 * `reset`/`token`/`user`/`throttled` poate ajunge pe ecran.
 *
 * Atenție la `user` („We can't find a user…"): pe ecranul de CERERE a linkului
 * (`PasswordResetLinkController`) rezultatul brokerului nu ajunge niciodată în răspuns —
 * deliberat, e chiar mecanismul de enumerare de conturi pe care OWASP cere să-l eviți, iar
 * acolo se randează un mesaj generic. Pe ecranul de SETARE a parolei noi, unde utilizatorul
 * are deja un token, divulgarea nu mai există, deci mesajul e util și se afișează.
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Password Reset Language Lines
    |--------------------------------------------------------------------------
    |
    | The following language lines are the default lines which match reasons
    | that are given by the password broker for a password update attempt
    | outcome such as failure due to an invalid password / reset token.
    |
    */

    'reset' => 'Your password has been reset.',
    'sent' => 'We have emailed your password reset link.',
    'throttled' => 'Please wait before retrying.',
    'token' => 'This password reset token is invalid.',
    'user' => "We can't find a user with that email address.",

];
