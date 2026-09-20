<?php

namespace App\Enums;

/**
 * Nivelul de acces derivat din `stripe_status` al abonamentului Throughput al tenantului
 * (specs.md §12.2, modelul de degradare pe 3 trepte, BR-BILL-04). Un SINGUR loc întoarce
 * asta — `App\Support\Billing\SubscriptionAccessPolicy` — citit de middleware-ul de
 * scriere și de bannerul din `AppLayout.tsx` deopotrivă, ca server și interfață să nu
 * poată diverge pe aceeași decizie.
 */
enum SubscriptionAccessLevel: string
{
    /** `active`/`past_due` (BR-BILL-03 — Stripe încă reîncearcă, eșecul nu e definitiv). */
    case Full = 'full';

    /** `unpaid` — vizualizare + export permise; creare/editare/ștergere blocate, orice rol. */
    case ReadOnly = 'read_only';

    /** `canceled` — nimic în afara paginii de billing/reactivare (+ export, §20.5). */
    case Blocked = 'blocked';
}
