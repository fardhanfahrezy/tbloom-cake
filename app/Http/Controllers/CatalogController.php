<?php

namespace App\Http\Controllers;

use App\CatalogQuery;
use App\Http\Requests\CatalogBrowseRequest;
use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\View\View;

class CatalogController extends Controller
{
    public function index(CatalogBrowseRequest $request, CatalogQuery $catalog): View
    {
        $category = $request->validated('category');
        $products = $catalog->forDate($request->pickupDate(), $category ? ProductCategory::from($category) : null)
            ->paginate(12)->withQueryString()->through(fn (Product $product): array => $catalog->present($product));

        return view('catalog.index', ['products' => $products, 'date' => $request->pickupDate(), 'category' => $category, 'window' => $request->window()]);
    }

    public function show(CatalogBrowseRequest $request, Product $product, CatalogQuery $catalog): View
    {
        $current = $catalog->forDate($request->pickupDate())->whereKey($product->id)->firstOrFail();

        return view('catalog.show', ['card' => $catalog->present($current), 'date' => $request->pickupDate(), 'window' => $request->window()]);
    }
}
