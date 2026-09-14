<?php

namespace App\Http\Controllers\Web\Products;

use App\Http\Controllers\Controller;
use App\Http\Requests\Products\StoreProductRequest;
use App\Http\Requests\Products\UpdateProductRequest;
use App\Http\Resources\Products\ProductDetailResource;
use App\Http\Resources\Products\ProductResource;
use App\Models\Product;
use App\Models\Variant;
use App\Support\Lists\CursorPage;
use App\Support\Lists\ProductList;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Catalog de produse — specs.md §10, Pachetul A punctul 1. Controller subțire: validarea
 * stă în FormRequests, forma de ieșire în Resources, regulile de drept în `ProductPolicy`.
 * Variantele au propriul controller (`VariantController`) — o variantă e o resursă
 * separată (SKU, preț, cost, stoc), nu un câmp al produsului.
 */
final class ProductController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Product::class);

        $list = new ProductList;
        $listQuery = $list->parse($request);
        $user = $request->user();

        return Inertia::render('Products/Index', [
            'products' => Inertia::defer(fn () => CursorPage::make(
                $listQuery->paginate($list->query($listQuery, $user)),
                ProductResource::class,
            )),
            'list' => $listQuery->toArray(),
            'can' => [
                'create' => $user->can('create', Product::class),
            ],
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Product::class);

        return Inertia::render('Products/Create');
    }

    public function store(StoreProductRequest $request): RedirectResponse
    {
        $product = new Product($request->validated());
        $product->save();

        return redirect()->route('products.show', $product)->with('success', 'Product created.');
    }

    public function show(Request $request, Product $product): Response
    {
        $this->authorize('view', $product);

        $product->load(['variants' => fn ($query) => $query->with('inventoryLevels')->orderBy('sku')]);
        $user = $request->user();

        return Inertia::render('Products/Show', [
            'product' => new ProductDetailResource($product),
            'deletionBlockedReason' => $product->deletionBlockedReason(),
            'can' => [
                'edit' => $user->can('update', $product),
                'delete' => $user->can('delete', $product),
                'createVariant' => $user->can('create', Variant::class),
            ],
        ]);
    }

    public function edit(Product $product): Response
    {
        $this->authorize('update', $product);

        return Inertia::render('Products/Edit', [
            'product' => new ProductDetailResource($product),
        ]);
    }

    public function update(UpdateProductRequest $request, Product $product): RedirectResponse
    {
        $product->update($request->validated());

        return redirect()->route('products.show', $product)->with('success', 'Product updated.');
    }

    public function destroy(Product $product): RedirectResponse
    {
        $this->authorize('delete', $product);

        if (($reason = $product->deletionBlockedReason()) !== null) {
            return back()->with('error', $reason);
        }

        $product->delete();

        return redirect()->route('products.index')->with('success', 'Product deleted.');
    }
}
