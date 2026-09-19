<?php

namespace App\Http\Controllers\Web\Products;

use App\Http\Controllers\Controller;
use App\Http\Requests\Products\StoreProductRequest;
use App\Http\Requests\Products\UpdateProductRequest;
use App\Http\Resources\Products\ProductDetailResource;
use App\Http\Resources\Products\ProductResource;
use App\Models\Product;
use App\Models\Variant;
use App\Support\Bulk\BulkConfirmationThreshold;
use App\Support\Bulk\BulkMatchingRowCount;
use App\Support\Bulk\BulkWritableResources;
use App\Support\Lists\CursorPage;
use App\Support\Lists\ProductList;
use App\Support\SavedViews\ListColumns;
use App\Support\SavedViews\SavedViewDefaultRedirect;
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
    public function index(Request $request): Response|RedirectResponse
    {
        $this->authorize('viewAny', Product::class);

        // Selector de coloane (specs.md §15.1, D2) — vezi docblock-ul echivalent din
        // `AccountController::index()`: implicitul salvat câștigă în fața filtrului de rol,
        // dar doar pe un URL fără NIMIC explicit încă (filtru, sortare, cursor SAU coloane).
        if (($redirect = SavedViewDefaultRedirect::resolve($request, 'products')) !== null) {
            return $redirect;
        }

        $list = new ProductList;
        $listQuery = $list->parse($request);
        $user = $request->user();

        return Inertia::render('Products/Index', [
            'products' => Inertia::defer(fn () => CursorPage::make(
                $listQuery->paginate($list->query($listQuery, $user)),
                ProductResource::class,
            )),
            // Pachetul C („bulk"), lotul E — vezi docblock-ul echivalent din
            // `AccountController::index()`. Fără restricție de proprietate (produsele n-au
            // owner, §7.4) — `BulkMatchingRowCount::for()` rămâne totuși sursa unică, ca
            // formula să nu se dubleze dacă regula se schimbă vreodată.
            'total' => Inertia::defer(fn () => BulkMatchingRowCount::for(
                $user,
                BulkWritableResources::resolve('products'),
                $list->query($listQuery, $user),
            )),
            'list' => $listQuery->toArray(),
            // Selector de coloane (specs.md §15.1) — validate server-side ca orice filtru;
            // un `?columns=` necunoscut/gol cade pe `SavedViewResourceType::defaultColumns()`.
            'columns' => ListColumns::fromRequest($request, 'products'),
            'can' => [
                'create' => $user->can('create', Product::class),
                // §13.5 — preț în masă + activare/dezactivare, un singur drept pentru
                // amândouă (Owner/Manager, `ProductPolicy::bulkWrite()`); Agent/Viewer nu
                // ajung aici (n-au `products.edit`).
                'bulkWrite' => $user->can('bulkWrite', Product::class),
            ],
            'bulkConfirmationThreshold' => BulkConfirmationThreshold::for($user),
            'bulkRowCap' => BulkConfirmationThreshold::rowCapForRole($user),
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
