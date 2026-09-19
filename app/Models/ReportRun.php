<?php

namespace App\Models;

use App\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * CONTRADICȚIE DE SPECIFICAȚIE (semnalată la review, nu „bug de Faza 1" — corectare a
 * încadrării din versiunea anterioară a acestui docblock). Faza 1 implementase exact ce
 * spuneau DOUĂ surse ale specificației: migrația proprie (comentariul „RLS — append-only
 * (log de execuție)") ȘI tabelul din specs.md §19.1, care marchează explicit
 * `report_runs` cu „Append-only: Da". §16.2 pct. 2-3 și pct. 5, în schimb, cer un flux
 * `queued` → `running` → `success`/`failed` PE ACELAȘI rând (`GenerateReportJob` scrie
 * `status`/`started_at`/`finished_at`/`file_path`/`row_count`/`error_message` progresiv,
 * exact ca `BulkOperation`) — imposibil sub `AppendOnly::bootAppendOnly()`, care aruncă
 * la orice `update()`/`save()` pe un rând existent. §16.2 și §19.1 nu pot fi ambele
 * adevărate simultan pentru acest model, de aceeași natură cu contradicția semnalată la
 * `ReportDefinition::TYPE_SAVED_VIEW_EXPORT` (§16.1). Am urmat §16.2 (fluxul operațional
 * explicit, cu pași concreți) și am scos trait-ul — vezi și `app/Concerns/AppendOnly.php`,
 * de unde `report_runs` a fost scos din listă (documentație rămasă acolo, acum greșită).
 * `deal_stage_events`/`activity_log` rămân corect append-only: acelea sunt jurnale de
 * evenimente DISCRETE, unde fiecare rând se scrie o singură dată și nu mai are ce
 * actualiza — spre deosebire de `report_runs`, cu ciclu de viață mutabil per rând.
 */
#[Fillable(['report_definition_id', 'status', 'started_at', 'finished_at', 'file_path', 'row_count', 'error_message', 'triggered_by'])]
class ReportRun extends Model
{
    use BelongsToTenant, HasUlids;

    // Fără `updated_at` (un log de execuție nu se „actualizează" în acel sens) — doar
    // `created_at`, populat de Postgres (`DEFAULT CURRENT_TIMESTAMP`, vezi migrația care
    // l-a adăugat), nu de Eloquent: `$timestamps = false` îl lasă complet pe seama DB-ului.
    public $timestamps = false;

    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    public const TRIGGERED_BY_SCHEDULER = 'scheduler';

    public const TRIGGERED_BY_MANUAL = 'manual';

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'row_count' => 'integer',
        ];
    }

    public function reportDefinition(): BelongsTo
    {
        return $this->belongsTo(ReportDefinition::class);
    }
}
