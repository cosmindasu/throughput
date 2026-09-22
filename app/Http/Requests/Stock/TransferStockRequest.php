<?php

namespace App\Http\Requests\Stock;

use App\Models\InventoryLevel;
use App\Models\Scopes\TenantScope;
use App\Models\StockMovement;
use App\Models\Variant;
use App\Support\LocaleFormat;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * BR-STOCK-03, US-STOCK-03 — transfer între locații pentru O variantă (dată de rută,
 * `/variants/{variant}/stock/transfer`).
 */
final class TransferStockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', StockMovement::class);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $tenantId = TenantScope::requireCurrentTenantId();

        $locationExists = Rule::exists('locations', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId));

        return [
            'from_location_id' => ['required', 'string', 'different:to_location_id', $locationExists],
            'to_location_id' => ['required', 'string', $locationExists],
            'quantity' => ['required', 'integer', 'min:1'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * Feedback rapid, pe câmp — verificarea SUB LOCK din `TransferStockAction` rămâne
     * garanția reală (o cursă concurentă poate goli stocul între acest request și
     * momentul tranzacției); aici e doar experiența de formular, nu ultima apărare.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->has('from_location_id') || $validator->errors()->has('quantity')) {
                return;
            }

            /** @var Variant $variant */
            $variant = $this->route('variant');
            $quantity = (int) $this->input('quantity');
            $fromLocationId = $this->input('from_location_id');

            $onHand = InventoryLevel::query()
                ->where('variant_id', $variant->getKey())
                ->where('location_id', $fromLocationId)
                ->value('on_hand') ?? 0;

            if ($quantity > $onHand) {
                $validator->errors()->add('quantity', __('rules.stock.transfer_available_at_source', ['available' => LocaleFormat::count($onHand)]));
            }
        });
    }
}
