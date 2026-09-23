@extends('errors.layout')

{{--
    HTTP-04, FR-I18N-04 — titlul refolosește cheia existentă din `lang/{en,fr}.json`
    ("Page Expired", commit c343858). Aruncat de `ValidateCsrfToken`, care rulează ÎNAINTEA
    lui `App\Http\Middleware\SetLocale` (acesta e `append`-uit, ultimul din grupul `web`) —
    limba vine din pasul dedicat din `bootstrap/app.php` (`withExceptions()->render()`).
--}}
@section('title', __('Page Expired'))
@section('code', '419')
@section('message', __('Your session has expired. Please refresh the page and try again.'))
