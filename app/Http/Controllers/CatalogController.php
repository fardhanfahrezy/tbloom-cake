<?php
namespace App\Http\Controllers;
use App\CatalogQuery;
use App\Http\Requests\CatalogBrowseRequest;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\View\View;
class CatalogController extends Controller
{
    public function index(CatalogBrowseRequest $request, CatalogQuery $catalog): View
    {
        $category = $request->validated('category');
        $products = $catalog->forDate($request->pickupDate(), $category)->paginate(12)->withQueryString()->through(fn (Product $product): array => $catalog->present($product));
        return view('catalog.index', ['products' => $products, 'date' => $request->pickupDate(), 'category' => $category,
            'categories' => Category::query()->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get(), 'window' => $request->window()]);
    }
    public function show(CatalogBrowseRequest $request, Product $product, CatalogQuery $catalog): View
    {
        $current = $catalog->forDate($request->pickupDate())->whereKey($product->id)->firstOrFail();
        $relatedProducts = Product::query()->where('is_active', true)->whereNull('archived_at')->where('id', '!=', $product->id)
            ->whereHas('category', fn (Builder $q) => $q->where('is_active', true))->whereHas('variants', fn (Builder $q) => $q->where('is_active', true))
            ->with(['category', 'variants' => fn (HasMany $q) => $q->where('is_active', true)->orderBy('price')->orderBy('id'), 'primaryImage'])
            ->when($product->category_id, fn (Builder $q) => $q->orderByRaw('CASE WHEN category_id = ? THEN 0 ELSE 1 END', [$product->category_id]))
            ->orderBy('name')->orderBy('id')->limit(6)->get()->filter(fn (Product $p): bool => $p->variants->isNotEmpty())->values();
        return view('catalog.show', ['card' => $catalog->present($current), 'date' => $request->pickupDate(), 'window' => $request->window(), 'relatedProducts' => $relatedProducts]);
    }
}
