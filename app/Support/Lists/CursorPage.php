<?php

namespace App\Support\Lists;

use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Forma `CursorPage<T>` din `resources/js/types/generated.d.ts` — traduce un
 * `CursorPaginator` Eloquent în props Inertia, printr-un `JsonResource` (regula 1 din
 * plan §1.2: niciun model brut). Un singur loc, ca fiecare listă pe cursor (Accounts,
 * apoi Contacts/Deals/Orders) să producă exact aceeași formă, nu câte o variantă.
 *
 * @param  class-string<JsonResource>  $resource
 * @return array{data: JsonResource, nextCursor: string|null, prevCursor: string|null}
 */
final class CursorPage
{
    public static function make(CursorPaginator $paginator, string $resource): array
    {
        return [
            'data' => $resource::collection($paginator->items()),
            'nextCursor' => $paginator->nextCursor()?->encode(),
            'prevCursor' => $paginator->previousCursor()?->encode(),
        ];
    }
}
