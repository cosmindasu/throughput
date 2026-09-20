<?php

namespace App\Http\Controllers\Web\Products;

use App\Http\Controllers\Controller;
use App\Http\Requests\Products\StoreVariantRequest;
use App\Http\Requests\Products\UpdateVariantRequest;
use App\Http\Resources\Products\ProductDetailResource;
use App\Http\Resources\Products\VariantResource;
use App\Models\Product;
use App\Models\Variant;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Variantele unui produs — specs.md §10.2, Pachetul A punctul 1. `cost` intră/iese din
 * acest formular ca orice alt câmp (autorizarea de `create`/`update` e deja restrânsă la
 * Owner/Manager prin `products.create`/`products.edit`, §7.4) — ascunderea din §7.4 vizează
 * strict AFIȘAREA pe `VariantResource` pentru Agent/Viewer, nu editarea, la care oricum
 * n-au acces.
 */
final class VariantController extends Controller
{
    public function create(Product $product): Response
    {
        $this->authorize('create', Variant::class);

        return Inertia::render('Variants/Create', [
            'product' => new ProductDetailResource($product),
        ]);
    }

    public function store(StoreVariantRequest $request, Product $product): RedirectResponse
    {
        $variant = new Variant($request->validated());
        $variant->product_id = $product->getKey();
        $variant->save();

        return redirect()->route('products.show', $product)->with('success', __('flash.products.variants.created'));
    }

    public function edit(Variant $variant): Response
    {
        $this->authorize('update', $variant);

        $variant->load('product');

        return Inertia::render('Variants/Edit', [
            'variant' => new VariantResource($variant),
            'product' => ['id' => $variant->product->id, 'name' => $variant->product->name],
        ]);
    }

    public function update(UpdateVariantRequest $request, Variant $variant): RedirectResponse
    {
        $variant->update($request->validated());

        return redirect()->route('products.show', $variant->product_id)->with('success', __('flash.products.variants.updated'));
    }

    public function destroy(Variant $variant): RedirectResponse
    {
        $this->authorize('delete', $variant);

        $productId = $variant->product_id;

        if (($reason = $variant->deletionBlockedReason()) !== null) {
            return back()->with('error', $reason);
        }

        $variant->delete();

        return redirect()->route('products.show', $productId)->with('success', __('flash.products.variants.deleted'));
    }
}
