<?php

namespace App\Support\SavedViews;

use App\Models\SavedViewDefault;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * FR-VIEW-02, penultimul paragraf: „La deschiderea listei fără filtre explicite în URL, se
 * aplică vederea implicită." Apelat din `AccountController::index()`/`DealController::index()`,
 * ÎNAINTE de `ResourceList::parse()` — dacă întoarce un redirect, controller-ul îl trimite
 * direct, fără să mai construiască pagina cu implicitul de rol (`owner=me` pentru Agent).
 *
 * De ce „fără filtre explicite" ÎNSEAMNĂ „fără `sort`" și nu doar „fără `filter`": propriul
 * redirect de mai jos scrie ÎNTOTDEAUNA `sort` în query string (`ListQuery::toArray()` nu-l
 * omite niciodată, nici când coincide cu implicitul listei) — exact ca URL-ul următor să aibă
 * mereu un semnal explicit și verificarea să nu se repete la infinit pe propriul redirect.
 */
final class SavedViewDefaultRedirect
{
    public static function resolve(Request $request, string $resourceType): ?RedirectResponse
    {
        if ($request->query('filter') !== null
            || $request->query('sort') !== null
            || $request->query('cursor') !== null
            || $request->query(ListColumns::QUERY_KEY) !== null) {
            return null;
        }

        $user = $request->user();

        $default = SavedViewDefault::query()
            ->where('user_id', $user->getKey())
            ->where('resource_type', $resourceType)
            ->first();

        if ($default === null) {
            return null;
        }

        $savedView = $default->savedView;

        if ($savedView === null) {
            // Vederea „Team" folosită ca implicit a fost ștearsă de altcineva (FK
            // `nullOnDelete`) — mesajul o singură dată, apoi rândul orfan dispare: următoarea
            // vizită găsește direct „niciun implicit", nu mai repetă notificarea.
            $default->delete();
            session()->flash('notice', 'The team view you used as default was deleted.');

            return null;
        }

        $list = SavedViewResourceType::list($resourceType);
        $listQuery = $list->fromState(['filter' => $savedView->filters, 'sort' => $savedView->sort]);
        $columns = ListColumns::fromState($savedView->columns, $resourceType);

        return redirect()->route(SavedViewResourceType::routeName($resourceType), [
            ...$listQuery->toArray(),
            'columns' => ListColumns::toQueryValue($columns),
        ]);
    }
}
