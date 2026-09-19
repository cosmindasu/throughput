<?php

namespace App\Mail;

use App\Mail\Transport\DemoInterceptingTransport;
use Illuminate\Mail\MailManager;
use Symfony\Component\Mailer\Transport\TransportInterface;

/**
 * BR-DEMO-02, specs.md §22.3, plan §10 — „un Transport Symfony Mailer custom (…) reutilizat
 * neschimbat de invitațiile de membri (Faza 5)".
 *
 * `createSymfonyTransport()` e apelată o singură dată per MAILER rezolvit
 * (`MailManager::build()`), indiferent de driver — singurul punct de extensie care acoperă
 * ORICE mailer configurat (`log` local, `resend` în producție — ADR-009, `smtp`, `array` în
 * teste, orice altul viitor) FĂRĂ să-i știe numele dinainte. Alternativa uzuală,
 * `Mail::extend($driver, …)`, leagă un rezolvator de un NUME de transport anume — ar trebui
 * înregistrată separat pentru fiecare driver și tot ar rata unul viitor.
 *
 * Suprascrierea DECOREAZĂ transportul real produs de părinte, nu îl înlocuiește (mandatul
 * pachetului, punctul 1) — Resend/SMTP/log/array rămân intacte, doar înfășurate.
 *
 * Vezi `App\Providers\AppServiceProvider::register()` pentru DE CE înregistrarea folosește
 * `$this->app->extend('mail.manager', …)` și nu `singleton()`.
 */
final class InterceptingMailManager extends MailManager
{
    public function createSymfonyTransport(array $config): TransportInterface
    {
        $transport = parent::createSymfonyTransport($config);

        return new DemoInterceptingTransport(
            $transport,
            (string) ($config['transport'] ?? $config['name'] ?? 'unknown'),
        );
    }
}
