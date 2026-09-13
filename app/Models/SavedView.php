<?php

namespace App\Models;

use App\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['resource_type', 'name', 'filters', 'columns', 'sort', 'visibility'])]
class SavedView extends Model
{
    use BelongsToTenant, HasUlids;

    public const VISIBILITY_PRIVATE = 'private';

    public const VISIBILITY_TEAM = 'team';

    /**
     * Tipurile din enum-ul de bază (§15.1) — mai largi decât `SavedViewResourceType::supported()`,
     * care listează doar resursele CU ecran de listă construit până acum (Accounts, Deals).
     *
     * @var list<string>
     */
    public const RESOURCE_TYPES = ['accounts', 'contacts', 'deals', 'orders', 'products', 'invoices'];

    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'columns' => 'array',
            'sort' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reportDefinitions(): HasMany
    {
        return $this->hasMany(ReportDefinition::class);
    }
}
