<?php

declare(strict_types=1);

namespace App\Support\Sentry;

use Sentry\Event;
use Sentry\EventHint;

/**
 * Curăță raportul de eroare înainte să plece către Sentry.
 *
 * Sentry e subprocesator (specs.md §28.2), iar un raport de eroare cară date pe care nimeni
 * n-a decis să le trimită: corpul cererii care a eșuat, parametri de formular, chei de API
 * din configurație. `send_default_pii = false` oprește ce atașează SDK-ul automat (IP,
 * identitatea utilizatorului); nu oprește ce ajunge acolo prin corpul cererii sau prin
 * `extra`. Asta face clasa de aici.
 *
 * E o clasă, nu un closure în `config/sentry.php`, pentru un motiv practic: un closure în
 * config rupe `php artisan config:cache` cu „Your configuration files are not serializable".
 * Referința `[ScrubSensitiveData::class, 'handle']` e un array de string-uri, deci se
 * serializează fără probleme.
 */
final class ScrubSensitiveData
{
    private const REDACTED = '[redactat]';

    /**
     * Chei redactate oriunde apar, la orice adâncime. Potrivirea e pe *substring*,
     * case-insensitive: prinde și `password_confirmation`, și `stripe_webhook_secret`,
     * fără să enumer fiecare variantă.
     *
     * @var list<string>
     */
    private const SENSITIVE = [
        'password',
        'passwd',
        'secret',
        'token',
        'authorization',
        'cookie',
        'api_key',
        'apikey',
        'credentials',
        'card',
        'cvc',
        'cvv',
        'iban',
        'stripe',
        'shippo',
        'dsn',
    ];

    public static function handle(Event $event, ?EventHint $hint = null): ?Event
    {
        $request = $event->getRequest();

        if ($request !== []) {
            $event->setRequest(self::scrub($request));
        }

        $extra = $event->getExtra();

        if ($extra !== []) {
            $event->setExtra(self::scrub($extra));
        }

        return $event;
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    private static function scrub(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && self::isSensitive($key)) {
                $data[$key] = self::REDACTED;

                continue;
            }

            if (is_array($value)) {
                $data[$key] = self::scrub($value);
            }
        }

        return $data;
    }

    private static function isSensitive(string $key): bool
    {
        $key = strtolower($key);

        foreach (self::SENSITIVE as $needle) {
            if (str_contains($key, $needle)) {
                return true;
            }
        }

        return false;
    }
}
