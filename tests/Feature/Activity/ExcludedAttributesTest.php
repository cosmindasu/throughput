<?php

namespace Tests\Feature\Activity;

use App\Support\Activity\ChangedAttributes;
use App\Support\Activity\ExcludedAttributes;
use Tests\TestCase;

/**
 * BR-AUD-01, specs.md §17.1 — „nu se loghează niciodată parole, token-uri sau secrete în
 * old_values/new_values ... aplicată la nivelul listener-ului, nu lăsată la latitudinea
 * fiecărui apel." Test de nivel PUR (fără DB/tenant): demonstrează exact excluderea, pe
 * ambele căi de scriere care o folosesc (`ChangedAttributes::diff()`/`fromEloquentUpdate()`).
 */
class ExcludedAttributesTest extends TestCase
{
    public function test_never_logged_fields_disappear_entirely(): void
    {
        $redacted = ExcludedAttributes::redact([
            'name' => 'Jane Doe',
            'password' => 'hashed-secret',
            'remember_token' => 'abc123',
            'credentials' => ['api_key' => 'sk_live_xxx'],
        ]);

        $this->assertSame(['name' => 'Jane Doe'], $redacted);
    }

    public function test_stripe_customer_id_is_masked_not_removed(): void
    {
        $redacted = ExcludedAttributes::redact(['stripe_customer_id' => 'cus_ABCDEFGH1234']);

        $this->assertSame('************1234', $redacted['stripe_customer_id']);
    }

    public function test_a_diff_that_only_touches_excluded_fields_becomes_null(): void
    {
        [$old, $new] = ChangedAttributes::diff(
            ['id' => '1', 'password' => 'old-hash'],
            ['id' => '1', 'password' => 'new-hash'],
        );

        $this->assertNull($old);
        $this->assertNull($new);
    }

    public function test_an_eloquent_style_update_excludes_the_password_field(): void
    {
        [$old, $new] = ChangedAttributes::fromEloquentUpdate(
            ['name' => 'Jane Doe', 'password' => 'old-hash'],
            ['name' => 'Jane Smith', 'password' => 'new-hash'],
        );

        $this->assertSame(['name' => 'Jane Doe'], $old);
        $this->assertSame(['name' => 'Jane Smith'], $new);
    }

    public function test_technical_columns_never_appear_in_a_snapshot(): void
    {
        $snapshot = ChangedAttributes::snapshot([
            'id' => '01ABC',
            'tenant_id' => '01TENANT',
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
            'name' => 'Acme Corp',
        ]);

        $this->assertSame(['name' => 'Acme Corp'], $snapshot);
    }
}
