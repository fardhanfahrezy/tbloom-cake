<?php

namespace App;

use App\Models\AuditLog;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CatalogManager
{
    /** @param array<string, mixed> $data */
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

    /** @param array<string, mixed> $data */
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
