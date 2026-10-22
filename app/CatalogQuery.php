<?php

namespace App;

use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CatalogQuery
{
    /** @return Builder<Product> */
    public function forDate(string $date, ?ProductCategory $category = null): Builder
    {
        $allocated = OrderItem::query()->selectRaw('COALESCE(SUM(quantity), 0)')
            ->join('daily_availabilities', 'daily_availabilities.id', '=', 'order_items.daily_availability_id')
            ->whereColumn('order_items.product_id', 'products.id')
            ->where('daily_availabilities.availability_date', $date)
            ->whereNull('order_items.quota_released_at');

        return Product::query()->select('products.*')->selectSub($allocated, 'allocated_units')
            ->where('is_active', true)->whereNull('archived_at')
            ->when($category, fn (Builder $query) => $query->where('category', $category))
            ->with([
                'variants' => fn (HasMany $query) => $query->where('is_active', true)->orderBy('price')->orderBy('id'),
                'images',
                'dailyAvailabilities' => fn (HasMany $query) => $query->where('availability_date', $date),
            ])->orderBy('name')->orderBy('id');
    }

    /** @return array{product: Product, quota: int, allocated: int, remaining: int} */
    public function present(Product $product): array
    {
        $quota = $product->dailyAvailabilities->first()?->quota_limit ?? $product->default_daily_quota;
        $allocated = (int) $product->getAttribute('allocated_units');

        return ['product' => $product, 'quota' => $quota, 'allocated' => $allocated, 'remaining' => max(0, $quota - $allocated)];
    }
}
