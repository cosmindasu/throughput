<?php

namespace App\Http\Requests\Reports;

use App\Enums\ReportFormat;
use App\Models\ReportDefinition;
use App\Models\SavedView;
use App\Support\Exports\ExportableResources;
use App\Support\SavedViews\SavedViewResourceType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * §16.1/§16.4 — actualizare de `report_definitions`. `reports.manage` (Owner/Manager, §7.4).
 * Reguli identice cu `StoreReportRequest` (formular comun pe front, `ReportForm.tsx`) —
 * NU se schimbă `report_type`/`saved_view_id` după creare (simplifică deliberat: un
 * raport care își schimbă sursa e, conceptual, un raport nou; formularul de editare nu
 * expune aceste două câmpuri, dar cererea le validează defensiv dacă ar veni oricum).
 */
final class UpdateReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('report'));
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'report_type' => ['required', Rule::in([
                ReportDefinition::TYPE_SAVED_VIEW_EXPORT,
                ReportDefinition::TYPE_DEAL_VELOCITY,
                ReportDefinition::TYPE_INVENTORY_VALUATION,
            ])],
            'saved_view_id' => ['required_if:report_type,'.ReportDefinition::TYPE_SAVED_VIEW_EXPORT, 'nullable', 'string'],
            'format' => ['required', Rule::in(array_map(fn (ReportFormat $f) => $f->value, ReportFormat::cases()))],
            'schedule_frequency' => ['required', Rule::in([
                ReportDefinition::FREQUENCY_NONE,
                ReportDefinition::FREQUENCY_DAILY,
                ReportDefinition::FREQUENCY_WEEKLY,
                ReportDefinition::FREQUENCY_MONTHLY,
            ])],
            'schedule_time' => ['required_unless:schedule_frequency,'.ReportDefinition::FREQUENCY_NONE, 'nullable', 'date_format:H:i'],
            'schedule_day' => ['nullable', 'integer', 'min:1', 'max:31'],
            'recipients' => ['required', 'array', 'min:1'],
            'recipients.*' => ['required', 'email', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->input('report_type') !== ReportDefinition::TYPE_SAVED_VIEW_EXPORT) {
                return;
            }

            $savedViewId = $this->input('saved_view_id');

            if (! is_string($savedViewId) || $savedViewId === '') {
                return;
            }

            $savedView = SavedView::query()->find($savedViewId);

            if ($savedView === null) {
                $validator->errors()->add('saved_view_id', __('rules.reports.saved_view_not_found'));

                return;
            }

            if (! $this->user()->can('view', $savedView)) {
                $validator->errors()->add('saved_view_id', __('rules.reports.saved_view_forbidden'));

                return;
            }

            if (! array_key_exists($savedView->resource_type, ExportableResources::map())
                || ! SavedViewResourceType::isSupported($savedView->resource_type)) {
                $validator->errors()->add(
                    'saved_view_id',
                    __('rules.reports.saved_view_unsupported_type', ['type' => $savedView->resource_type]),
                );
            }
        });
    }
}
