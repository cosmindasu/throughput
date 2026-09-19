<?php

namespace App\Models;

use App\Concerns\AppendOnly;
use App\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Jurnalul „Sent Emails" (BR-DEMO-02, specs.md §22.3) — un rând per email văzut de
 * `App\Mail\Transport\DemoInterceptingTransport`, indiferent dacă a ajuns cu adevărat la
 * transportul real sau a fost interceptat.
 *
 * `tenant_id` NULLABIL, deliberat — vezi migrația pentru politica RLS completă și
 * argumentarea celor trei opțiuni cântărite. Pe scurt: nu orice email tranzacțional are un
 * tenant la momentul trimiterii (recuperarea parolei, FR-PUB-05, pleacă de pe rute `guest`,
 * înainte de orice context de workspace). Un rând fără tenant NU e vizibil sub NICIUN
 * context de tenant activ — doar retenția (job de sistem, fără context) îl poate atinge.
 *
 * `AppendOnly`: un rând scris o dată nu se editează — o corecție e o interceptare nouă, nu
 * o rescriere a uneia vechi. Retenția (`App\Jobs\System\PruneSentEmailsJob`) șterge în masă
 * direct prin query builder, ocolind intenționat evenimentele de model ale acestui trait
 * (un `Model::query()->where(...)->delete()` NU declanșează `deleting` per rând) — vezi
 * jobul.
 */
#[Fillable(['tenant_id', 'mailer', 'status', 'subject', 'from_address', 'from_name', 'recipients', 'html_body', 'text_body', 'redacted'])]
class SentEmail extends Model
{
    use AppendOnly, BelongsToTenant, HasUlids;

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_INTERCEPTED = 'intercepted';

    public const STATUS_PARTIAL = 'partial';

    // Eșec al transportului REAL (Resend jos, timeout) — distinct de `intercepted`/
    // `partial`, care descriu o DECIZIE a transportului de interceptare, nu un eșec al
    // celui din spate. Niciodată scris ca „delivered": DemoInterceptingTransport prinde
    // eșecul înainte de a jurnaliza (vezi acolo).
    public const STATUS_FAILED = 'failed';

    // Jurnal — tabela are doar `created_at`, fără `updated_at` (ca `activity_log`).
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'recipients' => 'array',
            'redacted' => 'boolean',
            'created_at' => 'datetime',
        ];
    }
}
