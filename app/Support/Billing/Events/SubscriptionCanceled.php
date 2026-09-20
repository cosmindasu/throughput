<?php

namespace App\Support\Billing\Events;

/**
 * specs.md §12.2, tabelul de degradare — rândul `canceled`, coloana „Email către Owner":
 * „La tranziție". Declanșat din ACELAȘI `if` care scrie `tenants.subscription_canceled_at`
 * (BR-BILL-05) — o singură dată, la prima tranziție reală spre `canceled`, indiferent dacă
 * a ajuns prin `customer.subscription.updated` (status = canceled) sau
 * `customer.subscription.deleted`.
 */
final class SubscriptionCanceled
{
    public function __construct(
        public readonly string $tenantId,
    ) {}
}
