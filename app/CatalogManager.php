<?php

namespace App;

use App\Models\AuditLog;
use App\Models\DailyAvailability;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CatalogManager
{
    /** @param array{name: string, slug: string, category: string, description?: ?string, sales_unit_label: string, is_active: bool|int|string, archived: bool|int|string} $data */
    public function saveProduct(User $actor, array $data, ?Product $product = null): Product
    {
        return DB::transaction(function () use ($actor, $data, $product): Product {
            $currentActor = $this->lockStaff($actor);
            $current = $product ? Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail() : new Product;
            $created = ! $current->exists;
            $before = $created ? null : ['is_active' => $current->is_active, 'archived' => $current->archived_at !== null];
            $current->fill(Arr::only($data, ['name', 'slug', 'category', 'description', 'sales_unit_label', 'is_active']));
            $current->archived_at = $data['archived'] ? ($current->archived_at ?? now()->startOfSecond()) : null;
            if ($data['archived']) {
                $current->is_active = false;
            }
            if (! $current->isDirty()) {
                return $current;
            }
            $fields = array_keys($current->getDirty());
            $current->save();
            $this->audit($currentActor, 'product', $current->id, $created ? 'catalog.product_created' : 'catalog.product_updated', $before,
                ['changed_fields' => $fields, 'is_active' => $current->is_active, 'archived' => $current->archived_at !== null], 'Staf memperbarui katalog produk.');

            return $current;
        });
    }

    /** @param array{size_label: string, price: int|string, is_active: bool|int|string} $data */
    public function saveVariant(User $actor, Product $product, array $data, ?ProductVariant $variant = null): ProductVariant
    {
        return DB::transaction(function () use ($actor, $product, $data, $variant): ProductVariant {
            $currentActor = $this->lockStaff($actor);
            $currentProduct = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            $current = $variant ? $currentProduct->variants()->whereKey($variant->id)->lockForUpdate()->firstOrFail() : new ProductVariant(['product_id' => $currentProduct->id]);
            $created = ! $current->exists;
            $before = $created ? null : ['price' => $current->price, 'is_active' => $current->is_active];
            $current->fill(Arr::only($data, ['size_label', 'price', 'is_active']));
            if (! $current->isDirty()) {
                return $current;
            }
            $current->save();
            $this->audit($currentActor, 'product_variant', $current->id, $created ? 'catalog.variant_created' : 'catalog.variant_updated', $before,
                ['price' => $current->price, 'is_active' => $current->is_active], 'Staf memperbarui ukuran dan harga produk.');

            return $current;
        });
    }

    /**
     * @param  array{alt_text: string, sort_order: int|string, path?: string}  $data  Verified path from CatalogImageStorage only.
     * @return array{image: ProductImage, replaced_path: ?string}
     */
    public function saveImage(User $actor, Product $product, array $data, ?ProductImage $image = null): array
    {
        return DB::transaction(function () use ($actor, $product, $data, $image): array {
            $currentActor = $this->lockStaff($actor);
            $currentProduct = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            $current = $image ? $currentProduct->images()->whereKey($image->id)->lockForUpdate()->firstOrFail() : new ProductImage(['product_id' => $currentProduct->id]);
            $created = ! $current->exists;
            $oldPath = $created ? null : $current->path;
            $current->fill(Arr::only($data, ['path', 'alt_text', 'sort_order']));
            if (! $current->isDirty()) {
                return ['image' => $current, 'replaced_path' => null];
            }
            $current->save();
            $this->audit($currentActor, 'product_image', $current->id, $created ? 'catalog.image_created' : 'catalog.image_updated', null,
                ['sort_order' => $current->sort_order], 'Staf memperbarui foto produk.');

            return ['image' => $current, 'replaced_path' => $oldPath !== $current->path ? $oldPath : null];
        });
    }

    public function removeImage(User $actor, Product $product, ProductImage $image): string
    {
        return DB::transaction(function () use ($actor, $product, $image): string {
            $currentActor = $this->lockStaff($actor);
            $currentProduct = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            $current = $currentProduct->images()->whereKey($image->id)->lockForUpdate()->firstOrFail();
            $path = $current->path;
            $this->audit($currentActor, 'product_image', $current->id, 'catalog.image_deleted', ['sort_order' => $current->sort_order], [], 'Staf menghapus foto produk.');
            $current->delete();

            return $path;
        });
    }

    public function setDefaultQuota(User $actor, Product $product, int $quota, string $reason): Product
    {
        return DB::transaction(function () use ($actor, $product, $quota, $reason): Product {
            $currentActor = $this->lockStaff($actor);
            $current = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            if ($current->default_daily_quota === $quota) {
                return $current;
            }
            $before = ['default_daily_quota' => $current->default_daily_quota];
            $current->update(['default_daily_quota' => $quota]);
            $this->audit($currentActor, 'product', $current->id, 'catalog.default_quota_updated', $before, ['default_daily_quota' => $quota], $reason);

            return $current;
        });
    }

    public function setDailyQuota(User $actor, Product $product, string $date, int $quota, string $reason): DailyAvailability
    {
        return DB::transaction(function () use ($actor, $product, $date, $quota, $reason): DailyAvailability {
            $currentActor = $this->lockStaff($actor);
            $currentProduct = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            $current = DailyAvailability::query()->where('product_id', $currentProduct->id)->where('availability_date', $date)->lockForUpdate()->first();
            $created = $current === null;
            if (! $current) {
                $current = DailyAvailability::create(['product_id' => $currentProduct->id, 'availability_date' => $date, 'quota_limit' => $currentProduct->default_daily_quota, 'updated_by' => $currentActor->id]);
            }
            $allocated = (int) OrderItem::query()->where('daily_availability_id', $current->id)->whereNull('quota_released_at')->sum('quantity');
            if ($quota < $allocated) {
                throw ValidationException::withMessages(['quota_limit' => "Kapasitas tidak boleh kurang dari {$allocated} unit yang dialokasikan."]);
            }
            if (! $created && $current->quota_limit === $quota) {
                return $current;
            }
            $before = $created ? null : ['quota_limit' => $current->quota_limit];
            $current->update(['quota_limit' => $quota, 'updated_by' => $currentActor->id]);
            $this->audit($currentActor, 'daily_availability', $current->id, 'catalog.daily_quota_updated', $before,
                ['availability_date' => $date, 'quota_limit' => $quota, 'allocated_units' => $allocated], $reason);

            return $current;
        });
    }

    private function lockStaff(User $actor): User
    {
        $current = User::query()->whereKey($actor->id)->lockForUpdate()->first();
        if (! $current || ! $current->is_active || ! in_array($current->role, [UserRole::Admin, UserRole::Owner], true)) {
            throw new AuthorizationException('Hanya staf aktif yang dapat mengelola katalog.');
        }

        return $current;
    }

    /** @param array<string, mixed>|null $before @param array<string, mixed> $after */
    private function audit(User $actor, string $entity, int $id, string $action, ?array $before, array $after, string $reason): void
    {
        $audit = new AuditLog;
        $audit->forceFill(['actor_user_id' => $actor->id, 'actor_type' => 'user', 'entity_type' => $entity, 'entity_id' => $id,
            'action' => $action, 'before_redacted' => $before, 'after_redacted' => $after, 'reason' => $reason, 'correlation_id' => (string) Str::uuid()])->save();
    }
}
