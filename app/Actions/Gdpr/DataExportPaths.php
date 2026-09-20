<?php

namespace App\Actions\Gdpr;

/**
 * Locul de pe disc al unui export GDPR (FR-GDPR-01, specs.md §20.5) — un singur loc care
 * știe schema de căi, folosit de jobul care scrie părțile, de cel care arhivează, de
 * controllerul de descărcare și de jobul de curățare.
 *
 * **Rădăcină SEPARATĂ de `exports/`, deliberat.** `App\Jobs\System\PruneExpiredExportsJob`
 * (plan §7.2) mătură `exports/` cu trei treceri, dintre care una șterge orice FIȘIER
 * nereferit de `bulk_operations.result_path`, iar alta șterge întreg folderul oricărui
 * subdirector din `exports/` care nu e un ULID de tenant cunoscut — inclusiv, deci, un
 * `exports/gdpr/...` scris de aici. Arhivele GDPR trăiesc sub `gdpr-exports/`, cu propria
 * curățare (`App\Jobs\Gdpr\PruneExpiredDataExportsJob`), ca cele două retenții să nu se
 * calce reciproc.
 *
 * Structura, per cerere:
 *
 *     gdpr-exports/{tenantId}/{requestId}/            ← părți intermediare, șterse la final
 *     gdpr-exports/{tenantId}/{requestId}/accounts.json
 *     gdpr-exports/{tenantId}/{requestId}/accounts.meta.json
 *     gdpr-exports/{tenantId}/{requestId}.zip         ← livrabilul, `file_path` în bază
 */
final class DataExportPaths
{
    public const DISK = 'local';

    public const ROOT = 'gdpr-exports';

    public static function tenantFolder(string $tenantId): string
    {
        return self::ROOT."/{$tenantId}";
    }

    /**
     * Directorul de lucru al unei cereri: fiecare job de entitate scrie aici, iar
     * arhivarea îl șterge integral după ce ZIP-ul e închis.
     */
    public static function workFolder(string $tenantId, string $requestId): string
    {
        return self::tenantFolder($tenantId)."/{$requestId}";
    }

    public static function part(string $tenantId, string $requestId, string $file): string
    {
        return self::workFolder($tenantId, $requestId)."/{$file}";
    }

    public static function archive(string $tenantId, string $requestId): string
    {
        return self::tenantFolder($tenantId)."/{$requestId}.zip";
    }

    /**
     * Numele sub care arhiva ajunge în browser — slug-ul workspace-ului plus data cererii,
     * nu ULID-ul intern: fișierul ajunge, prin ipoteză, la un terț care răspunde unei
     * cereri de portabilitate (US-GDPR-01), nu în mâna cuiva care știe ce e un ULID.
     */
    public static function downloadName(string $workspaceSlug, string $requestedAt): string
    {
        return "{$workspaceSlug}-data-export-{$requestedAt}.zip";
    }
}
