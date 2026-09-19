<?php

namespace App\Policies;

use App\Models\User;
use App\Support\DemoMode;

/**
 * BR-DEMO-02, specs.md §22.3 — gardă pe `sent_emails.view` (Owner/Manager), NU pe
 * `settings.view` (au și Agent/Viewer — App\Support\Permissions::forRoles()): jurnalul
 * „Sent Emails" păstrează CONȚINUTUL COMPLET al fiecărui email, un nivel de acces diferit
 * de „poate deschide Settings".
 *
 * Permisiunea e dedicată, nu o reutilizare a lui `activity_log.view` — aceeași vizibilitate
 * azi, dar altă natură a datelor (vezi motivarea din catalog). Precedentul din proiect e
 * `unassigned.view`, despărțit deliberat de `members.view`.
 */
class SentEmailPolicy
{
    /**
     * Gardat ȘI de `DemoMode::enabled()`, nu doar de permisiune: interceptarea e un
     * guardrail de demo public, iar cu `DEMO_MODE=false` transportul nu mai scrie niciun
     * rând (decizie a lotului care l-a construit). Fără garda asta, ecranul ar rămâne
     * permanent gol, fără nicio explicație — iar proiectul ascunde ce nu se poate folosi,
     * nu îl dezactivează (FR-RBAC-01, §7.3: „un buton fără drept e ABSENT").
     *
     * Aici, nu în controller: altfel un link direct ar ocoli ascunderea cardului.
     */
    public function viewAny(User $user): bool
    {
        return DemoMode::enabled() && $user->can('sent_emails.view');
    }
}
