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
        $baseQuery = fn () => Product::query()
            ->where('is_active', true)
            ->whereNull('archived_at')
            ->whereHas('variants', fn (Builder $query) => $query->where('is_active', true))
            ->with([
                'variants' => fn (HasMany $query) => $query->where('is_active', true)->orderBy('price')->orderBy('id'),
                'primaryImage',
            ]);

        $categoryDefinitions = [
            ['value' => ProductCategory::Simple->value, 'enum' => ProductCategory::Simple, 'name' => 'Simple Cake', 'description' => 'Kue tart lembut berhias minimalis elegan untuk perayaan hangat keluarga.'],
            ['value' => ProductCategory::Cupcake->value, 'enum' => ProductCategory::Cupcake, 'name' => 'Cupcake', 'description' => 'Porsi personal praktis dengan buttercream lembut dan aneka hiasan cantik.'],
            ['value' => ProductCategory::Bento->value, 'enum' => ProductCategory::Bento, 'name' => 'Bento Cake', 'description' => 'Kue mini berukuran pas untuk kejutan manis dan momen personal tak terlupakan.'],
        ];

        // Select a representative product for each category before applying global product limit.
        // Prioritize image-bearing products so categories and hero do not lose imagery.
        $categoryRepresentatives = collect($categoryDefinitions)->mapWithKeys(function (array $def) use ($baseQuery) {
            $product = (clone $baseQuery())
                ->where('category', $def['enum'])
                ->whereHas('primaryImage')
                ->orderBy('name')
                ->orderBy('id')
                ->first()
                ?? (clone $baseQuery())
                    ->where('category', $def['enum'])
                    ->orderBy('name')
                    ->orderBy('id')
                    ->first();

            return [$def['value'] => $product];
        });

        $categories = collect($categoryDefinitions)->map(fn (array $category): array => [
            'value' => $category['value'],
            'name' => $category['name'],
            'description' => $category['description'],
            'product' => $categoryRepresentatives->get($category['value']),
        ]);

        $products = (clone $baseQuery())
            ->orderBy('name')
            ->orderBy('id')
            ->limit(6)
            ->get()
            ->filter(fn (Product $product): bool => $product->variants->isNotEmpty())
            ->values();

        $heroProduct = $products->first(fn (Product $p) => $p->primaryImage !== null)
            ?? $categoryRepresentatives->filter(fn (?Product $p) => $p?->primaryImage !== null)->first()
            ?? (clone $baseQuery())->whereHas('primaryImage')->orderBy('name')->orderBy('id')->first();

        $testimonials = Testimonial::query()
            ->where('publication_status', TestimonialPublicationStatus::Published)
            ->orderBy('sort_order')
            ->orderByDesc('id')
            ->limit(3)
            ->get();

        return view('welcome', compact('products', 'categories', 'testimonials', 'heroProduct'));
    }
}
