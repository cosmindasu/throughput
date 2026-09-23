@extends('errors.layout')

{{--
    HTTP-04, FR-I18N-04 — titlul refolosește cheia existentă din `lang/{en,fr}.json`
    ("Not Found", commit c343858). O rută inexistentă e aruncată de router ÎNAINTE ca grupul
    `web` să se aplice (`App\Http\Middleware\SetLocale` nu rulează pe calea asta) — limba
    vine din pasul dedicat din `bootstrap/app.php` (`withExceptions()->render()`), nu de-aici.
    Fără mesaj din excepție, deliberat, ca peste tot în fișierul ăsta: vezi `500.blade.php`.
--}}
@section('title', __('Not Found'))
@section('code', '404')
@section('message', __('The page you are looking for could not be found or may have been moved.'))
