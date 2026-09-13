<?php

namespace App\Models;

use App\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['pipeline_id', 'name', 'position', 'is_won', 'is_lost', 'probability'])]
class Stage extends Model
{
    use BelongsToTenant, HasUlids;

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'is_won' => 'boolean',
            'is_lost' => 'boolean',
            'probability' => 'integer',
        ];
    }

    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(Pipeline::class);
    }

    public function deals(): HasMany
    {
        return $this->hasMany(Deal::class);
    }

    public function transitionsFrom(): HasMany
    {
        return $this->hasMany(DealStageEvent::class, 'from_stage_id');
    }

    public function transitionsTo(): HasMany
    {
        return $this->hasMany(DealStageEvent::class, 'to_stage_id');
    }

    /**
     * BR-DEAL-01: o etapă cu deals asociate nu se poate șterge — indiferent dacă e marcată
     * Won/Lost sau nu, fiindcă `deals.stage_id` e o FK FĂRĂ cascadă (migrația deals). Ca la
     * `Account::deletionBlockedReason()` (§7.5), regula e o stare a înregistrării, nu un
     * drept al utilizatorului: amestecată în policy, `can.delete` ar fi ascuns butonul, iar
     * utilizatorul n-ar fi aflat niciodată DE CE nu poate șterge.
     *
     * A doua blocare, pentru o etapă fără deals: vezi `hasDealHistory()`.
     *
     * Acceptă `$dealsCount` și `$hasDealHistory` precalculate (StageResource randează liste
     * întregi cu `withCount('deals')` și `withExists` pe tranziții, ca să nu repete interogările
     * pe fiecare rând — N+1 pe ecranul de configurare).
     */
    public function deletionBlockedReason(?int $dealsCount = null, ?bool $hasDealHistory = null): ?string
    {
        $dealsCount ??= $this->deals()->count();

        if ($dealsCount > 0) {
            return $dealsCount === 1
                ? 'This stage has 1 deal on it. Move that deal to another stage before deleting it.'
                : "This stage has {$dealsCount} deals on it. Move them to another stage before deleting it.";
        }

        if ($hasDealHistory ?? $this->hasDealHistory()) {
            return "Deals have passed through this stage before, and their stage history still points at it, so it can't be deleted. You can rename it instead.";
        }

        return null;
    }

    /**
     * §9.1: `deal_stage_events` nu se editează și nu se șterge, deci o etapă prin care a trecut
     * vreodată un deal rămâne referită de istoric. FK-urile spre etapă sunt fără cascadă, deci
     * Postgres ar refuza oricum DELETE-ul, dar cu o eroare, nu cu un motiv de arătat.
     */
    public function hasDealHistory(): bool
    {
        return $this->transitionsTo()->exists() || $this->transitionsFrom()->exists();
    }
}
