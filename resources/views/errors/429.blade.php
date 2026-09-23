@extends('errors.layout')

{{--
    HTTP-04, FR-I18N-04 — titlul refolosește cheia existentă din `lang/{en,fr}.json`
    ("Too Many Requests", commit c343858).

    Mesajul excepției NU se afișează aici, deliberat — la fel ca vederea originală a
    framework-ului pe care o înlocuim (`vendor/laravel/framework/.../Exceptions/views/
    429.blade.php`, care de asemenea ignoră `$exception->getMessage()`).
    `App\Http\Controllers\Web\Auth\PasswordResetLinkController::store()` aruncă
    `abort(429, 'Too many password reset requests. Please try again later.')` — un literal
    ENGLEZ necatalogat, dar acceptat ca excepție EXPLICITĂ în
    `tests/Unit/ArchitectureTest.php::sinkExceptions()`, motivată tocmai de faptul că nicio
    vedere 429 nu-l randează. Dacă vederea de-aici ar arăta `$exception->getMessage()`,
    literalul ar deveni vizibil pe ecran — o regresie FR-I18N-04 NOUĂ, introdusă chiar de
    fixul HTTP-04.
--}}
@section('title', __('Too Many Requests'))
@section('code', '429')
@section('message', __('You have made too many requests. Please wait a moment and try again.'))
