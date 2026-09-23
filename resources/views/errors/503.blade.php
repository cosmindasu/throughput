@extends('errors.layout')

{{--
    HTTP-04, FR-I18N-04 — titlul refolosește cheia existentă din `lang/{en,fr}.json`
    ("Service Unavailable", commit c343858). Mesajul excepției NU se afișează — același motiv
    ca `500.blade.php` (poate purta text ne-localizat sau intern, ex. mesajul liber al
    `php artisan down --message=…`); nu depinde de starea de autentificare/tenant.
--}}
@section('title', __('Service Unavailable'))
@section('code', '503')
@section('message', __('The site is temporarily unavailable for maintenance. Please check back soon.'))
