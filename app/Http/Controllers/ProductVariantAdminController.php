<?php

namespace App\Http\Controllers;

use App\CatalogManager;
use App\Http\Requests\SaveProductVariantRequest;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ProductVariantAdminController extends Controller
{
    public function create(Product $product): View
    {
        Gate::authorize('update', $product);

        return view('staff.products.variant-form', ['product' => $product, 'variant' => new ProductVariant(['is_active' => true])]);
    }

    public function edit(Product $product, ProductVariant $variant): View
    {
        Gate::authorize('update', $product);

        return view('staff.products.variant-form', compact('product', 'variant'));
    }

    public function store(SaveProductVariantRequest $request, Product $product, CatalogManager $catalog): RedirectResponse
    {
        return $this->save($request, $product, $catalog);
    }

    public function update(SaveProductVariantRequest $request, Product $product, ProductVariant $variant, CatalogManager $catalog): RedirectResponse
    {
        return $this->save($request, $product, $catalog, $variant);
    }

    private function save(SaveProductVariantRequest $request, Product $product, CatalogManager $catalog, ?ProductVariant $variant = null): RedirectResponse
    {
        try {
            $catalog->saveVariant($request->user(), $product, $request->validated(), $variant);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['size_label' => 'Ukuran produk sudah digunakan.']);
        }

        return to_route('staff.products.edit', $product)->with('status', 'Ukuran dan harga berhasil disimpan.');
    }
}
