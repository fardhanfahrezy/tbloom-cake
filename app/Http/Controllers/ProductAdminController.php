<?php

namespace App\Http\Controllers;

use App\CatalogManager;
use App\Http\Requests\SaveProductRequest;
use App\Models\Product;
use App\Models\Category;
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

        $defaultCategory = Category::query()->where('slug', 'simple')->where('is_active', true)->first() ?? Category::query()->where('is_active', true)->orderBy('sort_order')->first();
        return view('staff.products.form', ['product' => new Product(['is_active' => true, 'category_id' => $defaultCategory?->id]), 'categories' => Category::query()->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get()]);
    }

    public function edit(Product $product): View
    {
        Gate::authorize('update', $product);
        $product->load(['variants' => fn (HasMany $query) => $query->orderBy('id'), 'images']);

        $categories = Category::query()->where('is_active', true)->orWhereKey($product->category_id)->orderBy('sort_order')->orderBy('name')->get();
        return view('staff.products.form', compact('product', 'categories'));
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
