<?php

namespace App\Support\SavedViews;

use Illuminate\Http\Request;

/**
 * Coloanele vizibile ale unei liste — partea `?columns=` a URL-ului (specs.md §15.1/§15.2),
 * analogă lui `App\Support\ListQuery` pentru filtre/sortare, dar ținută SEPARAT de el: nu
 * orice `ResourceList` are coloane configurabile (`ContactList`, `StockMovementList` —
 * fără selector), deci parsarea trăiește aici, lângă `SavedViewResourceType`, care ține deja
 * lista permisă/implicită per resursă. `ListQuery` (folosit de liste fără selector) rămâne
 * neatins.
 *
 * Forma URL-ului, coerentă cu `filter[...]`/`sort`: `?columns=owner,status,createdAt` —
 * o listă simplă, separată prin virgulă, ÎN ORDINEA de afișare aleasă de utilizator (nu un
 * `columns[]=` per valoare: ordinea contează aici, spre deosebire de `filter[...]`, care e
 * o hartă neordonată).
 *
 * Aceeași regulă ca la `ListQuery::fromRequest()`: o cheie NECUNOSCUTĂ sau nepermisă se
 * IGNORĂ, nu aruncă — un link vechi sau o vedere salvată cu coloane devenite invalide tot
 * trebuie să deschidă pagina, cu implicitul resursei, nu o eroare 500/422. O listă goală sau
 * complet invalidă cade pe `SavedViewResourceType::defaultColumns()`.
 */
final class ListColumns
{
    public const QUERY_KEY = 'columns';

    /** @return list<string> */
    public static function fromRequest(Request $request, string $resourceType): array
    {
        return self::sanitize($request->query(self::QUERY_KEY), $resourceType);
    }

    /**
     * Reface coloanele dintr-o stare deja canonică (`saved_views.columns`) — la fel ca
     * `ListQuery::fromState()`, fără cerere HTTP: aplicarea unei vederi salvate și
     * redirectul spre implicitul personal (`SavedViewDefaultRedirect`) trec amândouă pe aici.
     *
     * @return list<string>
     */
    public static function fromState(mixed $raw, string $resourceType): array
    {
        return self::sanitize($raw, $resourceType);
    }

    /**
     * @param  list<string>  $columns
     */
    public static function toQueryValue(array $columns): string
    {
        return implode(',', $columns);
    }

    /**
     * O listă goală sau complet invalidă cade pe implicitul resursei, NICIODATĂ pe o listă
     * goală de coloane — „minim o coloană opțională vizibilă” e o decizie de produs
     * deliberată (la fel ca identitatea, care nu poate fi ascunsă deloc), nu doar un artefact
     * al validării: `?columns=` gol înseamnă „n-am ales încă”, nu „ascunde tot”. Aceeași
     * regulă apără și UI-ul (`useListColumns.toggle` refuză să debifeze ultima coloană
     * rămasă), dar granița reală e AICI — orice alt apelant (job, comandă, un viitor
     * endpoint) primește tot implicitul, nu o listă goală.
     *
     * @return list<string>
     */
    private static function sanitize(mixed $raw, string $resourceType): array
    {
        $permitted = SavedViewResourceType::permittedColumns($resourceType);

        $requested = match (true) {
            is_string($raw) && $raw !== '' => explode(',', $raw),
            is_array($raw) => $raw,
            default => [],
        };

        $requested = array_values(array_filter(
            array_map(static fn ($value) => is_string($value) ? trim($value) : '', $requested),
            static fn (string $value) => $value !== '',
        ));

        // `array_intersect` păstrează ordinea primului argument (cea CERUTĂ, nu cea
        // canonică din registru) — reordonarea aleasă de utilizator supraviețuiește
        // validării, exact ce citește/rescrie selectorul de coloane.
        $valid = array_values(array_unique(array_intersect($requested, $permitted)));

        return $valid !== [] ? $valid : SavedViewResourceType::defaultColumns($resourceType);
    }
}
