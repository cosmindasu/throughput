<?php

namespace App\Http\Resources\Reports;

use App\Models\ReportDefinition;
use App\Support\Members\DeactivatedMemberNames;
use App\Support\Reports\BuiltInReports;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Un rând din `Reports/Index` sau detaliul din `Reports/Show` — specs.md §16.
 *
 * Controller-ul încarcă mereu `savedView`, `createdBy`, `latestRun` înainte de a construi
 * acest Resource (`ReportController::index()`/`show()`) — NU `whenLoaded()` aici: cu doar
 * două puncte de intrare, ambele disciplinate să facă eager-load, o verificare condiționată
 * ar ascunde tăcut un N+1 dacă cineva uită `->with(...)`, în loc să-l facă vizibil imediat.
 *
 * @mixin ReportDefinition
 */
class ReportDefinitionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $isBuiltIn = $this->isBuiltIn();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'reportType' => $this->report_type,
            'isBuiltIn' => $isBuiltIn,
            'sourceLabel' => $isBuiltIn
                ? BuiltInReports::resolve($this->report_type)->title()
                : ($this->savedView?->name ?? __('reports.saved_view_fallback')),
            'savedView' => $this->savedView !== null ? [
                'id' => $this->savedView->id,
                'name' => $this->savedView->name,
                'resourceType' => $this->savedView->resource_type,
            ] : null,
            'format' => $this->format,
            'scheduleFrequency' => $this->schedule_frequency,
            // `H:i` — `schedule_time` e un `time` Postgres, întors ca string ("07:00:00");
            // frontend-ul lucrează cu `HH:mm` (câmpul `<input type="time">`).
            'scheduleTime' => $this->schedule_time !== null ? substr((string) $this->schedule_time, 0, 5) : null,
            'scheduleDay' => $this->schedule_day,
            'recipients' => $this->recipients,
            'isActive' => (bool) $this->is_active,
            // FR-TEN-04 — un raport programat supraviețuiește autorului lui: cine l-a creat
            // rămâne vizibil în listă, cu marcajul „(deactivated)" când membership-ul lui a
            // fost dezactivat între timp (plan §11 — la nivelul `Resource`-ului).
            'createdBy' => DeactivatedMemberNames::label($this->createdBy?->name, $this->createdBy?->id),
            'lastRun' => $this->latestRun !== null ? new ReportRunResource($this->latestRun) : null,
            'canUpdate' => (bool) $request->user()?->can('update', $this->resource),
            'canDelete' => (bool) $request->user()?->can('delete', $this->resource),
            'canRunNow' => (bool) $request->user()?->can('runNow', $this->resource),
        ];
    }
}
