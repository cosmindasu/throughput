<?php

namespace App\Http\Resources;

use App\Models\Stage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * O etapă, pentru coloanele kanban (`Deals/Kanban`) și meniul „Move to stage…"
 * (`Deals/Show`). Numit `DealStage…`, nu `Stage…`: configurarea de pipeline/etape
 * (`/{w}/pipeline`, FR-DEAL-02) e alt pachet și își poate defini propriul Resource
 * pentru CRUD fără coliziune de nume la integrare.
 *
 * @mixin Stage
 */
class DealStageResource extends JsonResource
{
    /**
     * @return array{id: string, name: string, position: int, isWon: bool, isLost: bool, probability: int|null}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'position' => $this->position,
            'isWon' => $this->is_won,
            'isLost' => $this->is_lost,
            'probability' => $this->probability,
        ];
    }
}
