<?php

namespace App\Models;

use App\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'is_default'])]
class Pipeline extends Model
{
    use BelongsToTenant, HasUlids;

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
        ];
    }

    public function stages(): HasMany
    {
        return $this->hasMany(Stage::class);
    }

    public function deals(): HasMany
    {
        return $this->hasMany(Deal::class);
    }

    /**
     * MVP: un singur pipeline per tenant (specs.md §9.2, pipeline-uri multiple = FR-DEAL-04,
     * Faza 2 viitoare). Ecranul de configurare (Pachetul D) și acțiunile lui rezolvă mereu
     * pipeline-ul implicit prin acest punct unic — nicio rută nu poartă `{pipeline}` în cale.
     */
    public static function resolveDefault(): self
    {
        return static::query()->where('is_default', true)->firstOrFail();
    }
}
