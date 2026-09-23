@extends('errors.layout')

{{--
    HTTP-04, FR-I18N-04 — titlul refolosește cheia existentă din `lang/{en,fr}.json`
    ("Server Error", commit c343858).

    Mesajul excepției NU se afișează, NICIODATĂ — pentru un 500,
    `Illuminate\Foundation\Exceptions\Handler::prepareResponse()` reîmpachetează orice
    excepție ne-HTTP ca `new HttpException(500, $e->getMessage(), $e)` înainte de randare,
    deci `$exception->getMessage()` poartă textul BRUT al excepției ORIGINALE — interogări
    SQL, căi de fișier, mesaje din pachete terțe. Exact genul de scurgere de informație pe
    care o pagină de eroare publică n-are voie s-o arate, indiferent de starea de
    autentificare a cererii. Comportament identic cu vederea originală a framework-ului
    (`vendor/laravel/framework/.../Exceptions/views/500.blade.php`), care de asemenea
    ignoră mesajul.
--}}
@section('title', __('Server Error'))
@section('code', '500')
@section('message', __('Something went wrong on our end. Please try again in a few minutes.'))
