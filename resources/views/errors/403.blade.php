@extends('errors.layout')

{{--
    HTTP-04, FR-I18N-04 — titlul refolosește cheia deja existentă în `lang/{en,fr}.json`
    ("Forbidden"), adăugată de commit-ul c343858 pentru vederile de eroare ale framework-ului.
    `tests/Feature/I18n/ErrorViewLocaleTest.php` verifică `<title>` pe ambele limbi — NU
    redenumi cheia.

    Corpul arată motivul REAL al refuzului când unul există: fiecare `abort(403, …)`/
    `Response::deny(…)` din `app/` trece deja prin `__()` înainte de a ajunge aici
    (`App\Policies\MembershipPolicy`, `App\Support\DemoMode::refusal()`,
    `App\Http\Middleware\EnsureSubscriptionAccess`) — deci `$exception->getMessage()` e deja
    LOCALIZAT, nu un literal de reparat.

    FĂRĂ `e()` manual pe mesaj, deliberat: forma pe DOUĂ argumente a lui `@section(...)`
    (spre deosebire de `@section(...) … @endsection`) trece conținutul prin `e()` ea însăși
    (`Illuminate\View\Concerns\ManagesLayouts::startSection()` — `e($content)` necondiționat
    cât timp valoarea nu e o `View`), verificat direct în vendor. Un `e()` suplimentar aici ar
    scăpa DUBLU orice caracter special (`&` → `&amp;amp;`) — găsit la scrierea testelor
    (`tests/Feature/I18n/ServerStringsLot2Test.php`), nu presupus.

    `isset($exception)`, nu `$exception->getMessage()` direct: `Handler::renderHttpException()`
    trimite mereu variabila pe calea reală (403 dintr-un Policy/middleware), dar vederea
    trebuie să rămână randabilă și izolat — `view('errors.403')->render()`, fără nimic
    injectat — pentru `tests/Feature/I18n/ServerStringsLot2Test.php`, care verifică toate
    vederile din `resources/views/errors` fără excepții. O pagină de eroare care ea însăși
    aruncă o eroare la o variabilă lipsă e exact genul de fragilitate pe care n-are voie s-o
    aibă.
--}}
@section('title', __('Forbidden'))
@section('code', '403')
@section('message', isset($exception) && $exception->getMessage() !== '' ? $exception->getMessage() : __("You don't have permission to access this page."))
