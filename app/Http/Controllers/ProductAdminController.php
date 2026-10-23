<?php

namespace App\Http\Controllers;

use App\CatalogManager;
use App\Http\Requests\SaveProductRequest;
use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ProductAdminController extends Controller
{
    public function index(): View
    {
        Gate::authorize('viewAny', Product::class);

        return view('staff.products.index', ['products' => Product::query()->withCount('variants')->orderBy('name')->orderBy('id')->paginate(15)]);
    }

    public function create(): View
    {
        Gate::authorize('create', Product::class);

        return view('staff.products.form', ['product' => new Product(['is_active' => true, 'category' => ProductCategory::Simple])]);
    }

    public function edit(Product $product): View
    {
        Gate::authorize('update', $product);
        $product->load(['variants' => fn (HasMany $query) => $query->orderBy('id'), 'images']);

        return view('staff.products.form', ['product' => $product]);
    }

    public function store(SaveProductRequest $request, CatalogManager $catalog): RedirectResponse
    {
        return $this->save($request, $catalog);
    }

    public function update(SaveProductRequest $request, Product $product, CatalogManager $catalog): RedirectResponse
    {
        return $this->save($request, $catalog, $product);
    }

    private function save(SaveProductRequest $request, CatalogManager $catalog, ?Product $product = null): RedirectResponse
    {
        try {
            $saved = $catalog->saveProduct($request->user(), $request->validated(), $product);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['slug' => 'Slug produk sudah digunakan.']);
        }

        return to_route('staff.products.edit', $saved)->with('status', 'Produk berhasil disimpan.');
    }
}
