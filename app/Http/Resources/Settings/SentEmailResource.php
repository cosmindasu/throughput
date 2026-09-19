<?php

namespace App\Http\Resources\Settings;

use App\Models\SentEmail;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Contractul de props pentru Settings/SentEmails/Index (BR-DEMO-02, specs.md §22.3).
 * Mirror manual în `resources/js/types/generated.d.ts` (`SentEmailRow`) — plan §1.2 regula 5.
 *
 * @mixin SentEmail
 */
final class SentEmailResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'mailer' => $this->mailer,
            'status' => $this->status,
            'subject' => $this->subject,
            'fromAddress' => $this->from_address,
            'fromName' => $this->from_name,
            // Formă deja stabilă, scrisă de DemoInterceptingTransport::journal():
            // list<{type, address, name, allowed}> — vezi `SentEmailRecipient` din generated.d.ts.
            'recipients' => $this->recipients,
            // Corp REDACTAT la scriere (App\Support\Mail\SentEmailRedactor) — niciodată
            // conținut brut, indiferent de rol. `redacted` spune interfeței că a fost atins.
            'htmlBody' => $this->html_body,
            'textBody' => $this->text_body,
            'redacted' => $this->redacted,
            'createdAt' => $this->created_at?->toISOString(),
        ];
    }
}
