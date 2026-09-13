<?php

namespace Database\Seeders\Support;

use Illuminate\Support\Carbon;

/**
 * Istoricul semănat al `activity_log` (plan §7.8): rândurile există din Faza 1, ca
 * dashboard-ul (plan §7.4) să aibă ce arăta — instrumentarea live (observers pe scrieri
 * reale) se cablează abia în Faza 5 (specs.md §17). Un singur canal, folosit de toate
 * seederele de entități, ca formatul rândului să nu diveargă între ele.
 */
final class ActivityLogRecorder
{
    private const USER_AGENTS = [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0 Safari/537.36',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Safari/605.1.15',
        'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/127.0 Safari/537.36',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_5) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Safari/605.1.15',
    ];

    public function __construct(private readonly ChunkedWriter $writer) {}

    /** @param array<string, mixed>|null $oldValues @param array<string, mixed>|null $newValues */
    public function record(
        string $tenantId,
        ?string $userId,
        string $action,
        ?string $auditableType,
        ?string $auditableId,
        Carbon $createdAt,
        ?array $oldValues = null,
        ?array $newValues = null,
    ): void {
        $this->writer->push([
            'id' => DemoId::next(),
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'action' => $action,
            'auditable_type' => $auditableType,
            'auditable_id' => $auditableId,
            'old_values' => $oldValues !== null ? json_encode($oldValues) : null,
            'new_values' => $newValues !== null ? json_encode($newValues) : null,
            'ip_address' => long2ip(random_int(1, 4294967294)),
            'user_agent' => self::USER_AGENTS[array_rand(self::USER_AGENTS)],
            'created_at' => $createdAt,
        ]);
    }

    public function flush(): void
    {
        $this->writer->flush();
    }
}
