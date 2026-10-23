<?php

namespace App\Http\Controllers;

use App\CatalogImageStorage;
use App\CatalogManager;
use App\Http\Requests\SaveProductImageRequest;
use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Throwable;

class ProductImageAdminController extends Controller
{
    public function create(Product $product): View
    {
        Gate::authorize('update', $product);

        return view('staff.products.image-form', ['product' => $product, 'image' => new ProductImage(['sort_order' => 0])]);
    }

    public function edit(Product $product, ProductImage $image): View
    {
        Gate::authorize('update', $product);

        return view('staff.products.image-form', compact('product', 'image'));
    }

    public function store(SaveProductImageRequest $request, Product $product, CatalogManager $catalog, CatalogImageStorage $storage): RedirectResponse
    {
        return $this->save($request, $product, $catalog, $storage);
    }

    public function update(SaveProductImageRequest $request, Product $product, ProductImage $image, CatalogManager $catalog, CatalogImageStorage $storage): RedirectResponse
    {
        return $this->save($request, $product, $catalog, $storage, $image);
    }

    public function destroy(Request $request, Product $product, ProductImage $image, CatalogManager $catalog, CatalogImageStorage $storage): RedirectResponse
    {
        Gate::authorize('update', $product);
        $path = $catalog->removeImage($request->user(), $product, $image);
        $storage->discard($path);

        return to_route('staff.products.edit', $product)->with('status', 'Foto produk dihapus.');
    }

    private function save(SaveProductImageRequest $request, Product $product, CatalogManager $catalog, CatalogImageStorage $storage, ?ProductImage $image = null): RedirectResponse
    {
        $data = $request->safe()->only(['alt_text', 'sort_order']);
        $path = $request->hasFile('image') ? $storage->store($request->file('image')) : null;
        if ($path) {
            $data['path'] = $path;
        }
        try {
            $saved = $catalog->saveImage($request->user(), $product, $data, $image);
        } catch (Throwable $exception) {
            if ($path) {
                $storage->discard($path);
            }
            throw $exception;
        }
        if ($saved['replaced_path']) {
            $storage->discard($saved['replaced_path']);
        }

        return to_route('staff.products.edit', $product)->with('status', 'Foto produk berhasil disimpan.');
    }
}
