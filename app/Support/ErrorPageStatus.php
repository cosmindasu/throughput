<?php

namespace App\Support;

/**
 * Statusurile care au pagină de eroare proprie, în ambele randări ([[ADR-024]]): vederea
 * Blade din `resources/views/errors/{status}.blade.php` pentru o încărcare completă de
 * pagină, și `resources/js/Pages/Error.tsx` pentru o cerere Inertia.
 *
 * Cheile de traducere sunt ACELEAȘI în ambele — sunt cele pe care vederile Blade le trec deja
 * prin `__()`, din `lang/{en,fr}.json`. Pagina Inertia nu are catalog propriu: primește
 * textele rezolvate server-side, ca prop-uri. Două randări, o singură sursă pentru ce scrie
 * pe ecran.
 *
 * `ErrorPageTest` compară lista de-aici cu fișierele Blade existente și verifică faptul că
 * fiecare vedere chiar folosește cheile declarate mai jos — un status adăugat într-un singur
 * loc, sau un text schimbat doar în Blade, devine un test roșu, nu o divergență pe care o
 * descoperă un vizitator.
 */
final class ErrorPageStatus
{
    /**
     * Cheile de traducere per status, în ordinea `[titlu, mesaj]`.
     *
     * @var array<int, array{0: string, 1: string}>
     */
    public const COPY = [
        403 => ['Forbidden', "You don't have permission to access this page."],
        404 => ['Not Found', 'The page you are looking for could not be found or may have been moved.'],
        419 => ['Page Expired', 'Your session has expired. Please refresh the page and try again.'],
        429 => ['Too Many Requests', 'You have made too many requests. Please wait a moment and try again.'],
        500 => ['Server Error', 'Something went wrong on our end. Please try again in a few minutes.'],
        503 => ['Service Unavailable', 'The site is temporarily unavailable for maintenance. Please check back soon.'],
    ];

    /**
     * @return list<int>
     */
    public static function supported(): array
    {
        return array_keys(self::COPY);
    }

    public static function supports(int $status): bool
    {
        return array_key_exists($status, self::COPY);
    }

    /**
     * Textele traduse pentru un status, în limba deja fixată pe cerere de pasul de locale
     * din `bootstrap/app.php` ([[ADR-022]]).
     *
     * `$exceptionMessage` e folosit DOAR pe 403, oglindind exact ce face
     * `resources/views/errors/403.blade.php`: acolo mesajul chiar spune motivul refuzului, iar
     * fiecare `abort(403, …)`/`Response::deny(…)` din `app/` trece deja prin `__()`, deci e
     * localizat, nu un literal. Pe celelalte statusuri e ignorat deliberat — vederile Blade
     * nu-l folosesc nicăieri altundeva, iar pe un 500 ar scurge în pagină detalii interne.
     *
     * @return array{title: string, message: string}
     */
    public static function copyFor(int $status, ?string $exceptionMessage = null): array
    {
        [$title, $message] = self::COPY[$status];

        $useExceptionMessage = $status === 403
            && $exceptionMessage !== null
            && $exceptionMessage !== '';

        return [
            'title' => __($title),
            'message' => $useExceptionMessage ? $exceptionMessage : __($message),
        ];
    }
}
