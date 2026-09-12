<?php

namespace App\Support;

use App\Models\Scopes\TenantScope;
use Illuminate\Http\Request;

/**
 * „Recent accesate" din starea inițială a căutării globale (FR-SEARCH-01): ultimele
 * înregistrări deschise de utilizator, per workspace.
 *
 * În sesiune, nu în bază: nu sunt date de business, n-au nevoie de RLS, de seed sau de reset
 * (§22.1). Cheia include tenantul, ca lista unui workspace să nu apară în celălalt după
 * comutare. Paginile de detaliu o alimentează din `show()`; căutarea doar o citește.
 */
final class RecentlyViewed
{
    public const LIMIT = 8;

    /**
     * @param  'account'|'contact'|'deal'|'product'  $type
     */
    public static function record(Request $request, string $type, string $id, string $label, string $url): void
    {
        $key = self::key();

        $items = collect($request->session()->get($key, []))
            ->reject(fn (array $item) => $item['type'] === $type && $item['id'] === $id)
            ->prepend(['type' => $type, 'id' => $id, 'label' => $label, 'url' => $url])
            ->take(self::LIMIT)
            ->values()
            ->all();

        $request->session()->put($key, $items);
    }

    /**
     * @return list<array{type: string, id: string, label: string, url: string}>
     */
    public static function all(Request $request): array
    {
        return $request->session()->get(self::key(), []);
    }

    private static function key(): string
    {
        return 'recently_viewed.'.TenantScope::requireCurrentTenantId();
    }
}
