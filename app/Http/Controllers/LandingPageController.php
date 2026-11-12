<?php
namespace App\Http\Controllers;
use App\Models\Category;
use App\Models\Product;
use App\Models\Testimonial;
use App\Models\TestimonialPublicationStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\View\View;
class LandingPageController extends Controller
{
    public function index(): View
    {
        $baseQuery = fn () => Product::query()->where('is_active', true)->whereNull('archived_at')
            ->whereHas('category', fn (Builder $q) => $q->where('is_active', true))->whereHas('variants', fn (Builder $q) => $q->where('is_active', true))
            ->with(['category', 'variants' => fn (HasMany $q) => $q->where('is_active', true)->orderBy('price')->orderBy('id'), 'primaryImage']);
        $categories = Category::query()->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get()->map(function (Category $category) use ($baseQuery): array {
            $product = (clone $baseQuery())->where('category_id', $category->id)->whereHas('primaryImage')->orderBy('name')->orderBy('id')->first()
                ?? (clone $baseQuery())->where('category_id', $category->id)->orderBy('name')->orderBy('id')->first();
            return ['value' => $category->slug, 'name' => $category->name, 'description' => $category->description, 'product' => $product];
        });
        $products = (clone $baseQuery())->orderBy('name')->orderBy('id')->limit(6)->get()->filter(fn (Product $p): bool => $p->variants->isNotEmpty())->values();
        $heroProduct = $products->first(fn (Product $p) => $p->primaryImage !== null) ?? $categories->pluck('product')->first(fn (?Product $p) => $p?->primaryImage !== null)
            ?? (clone $baseQuery())->whereHas('primaryImage')->orderBy('name')->orderBy('id')->first();
        $testimonials = Testimonial::query()->where('publication_status', TestimonialPublicationStatus::Published)->orderBy('sort_order')->orderByDesc('id')->limit(3)->get();
        return view('welcome', compact('products', 'categories', 'testimonials', 'heroProduct'));
    }
}
