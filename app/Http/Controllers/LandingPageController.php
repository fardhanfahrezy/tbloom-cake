<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Testimonial;
use App\Models\TestimonialPublicationStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\View\View;

class LandingPageController extends Controller
{
    public function index(): View
    {
        $products = Product::query()
            ->where('is_active', true)
            ->whereNull('archived_at')
            ->whereHas('variants', fn (Builder $query) => $query->where('is_active', true))
            ->with([
                'variants' => fn (HasMany $query) => $query->where('is_active', true)->orderBy('price')->orderBy('id'),
                'images',
            ])
            ->orderBy('name')
            ->orderBy('id')
            ->limit(6)
            ->get();

        $productsByCategory = $products->keyBy(fn (Product $product): string => $product->category->value);

        $categories = collect([
            ['value' => ProductCategory::Simple->value, 'name' => 'Simple Cake', 'description' => 'Lorem ipsum dolor sit amet, consectetur adipiscing elit.'],
            ['value' => ProductCategory::Cupcake->value, 'name' => 'Cupcake', 'description' => 'Lorem ipsum dolor sit amet, consectetur adipiscing elit.'],
            ['value' => ProductCategory::Bento->value, 'name' => 'Bento Cake', 'description' => 'Lorem ipsum dolor sit amet, consectetur adipiscing elit.'],
        ])->map(fn (array $category): array => [
            ...$category,
            'product' => $productsByCategory->get($category['value']),
        ]);

        $testimonials = Testimonial::query()
            ->where('publication_status', TestimonialPublicationStatus::Published)
            ->orderBy('sort_order')
            ->orderByDesc('id')
            ->limit(3)
            ->get();

        return view('welcome', compact('products', 'categories', 'testimonials'));
    }
}
