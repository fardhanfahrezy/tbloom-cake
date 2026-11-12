<?php
namespace App;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
class CategoryManager
{
    public function save(User $actor, array $data, ?Category $category = null): Category
    {
        return DB::transaction(function () use ($actor, $data, $category): Category {
            $currentActor = $this->lockStaff($actor);
            $current = $category ? Category::query()->whereKey($category->id)->lockForUpdate()->firstOrFail() : new Category;
            $created = ! $current->exists;
            $before = $created ? null : ['name' => $current->name, 'slug' => $current->slug, 'is_active' => $current->is_active, 'sort_order' => $current->sort_order];
            $current->fill(Arr::only($data, ['name', 'slug', 'description', 'sort_order', 'is_active']));
            if (! $current->isDirty()) { return $current; }
            $current->save();
            $this->audit($currentActor, $current, $created ? 'catalog.category_created' : 'catalog.category_updated', $before,
                ['name' => $current->name, 'slug' => $current->slug, 'is_active' => $current->is_active, 'sort_order' => $current->sort_order],
                $created ? 'Staf membuat kategori katalog.' : 'Staf memperbarui kategori katalog.');
            return $current;
        });
    }
    public function delete(User $actor, Category $category): void
    {
        DB::transaction(function () use ($actor, $category): void {
            $currentActor = $this->lockStaff($actor);
            $current = Category::query()->whereKey($category->id)->lockForUpdate()->firstOrFail();
            if ($current->products()->exists()) {
                throw ValidationException::withMessages(['category' => 'Kategori tidak dapat dihapus karena masih memiliki produk. Pindahkan produk ke kategori lain terlebih dahulu.']);
            }
            $before = ['name' => $current->name, 'slug' => $current->slug];
            $this->audit($currentActor, $current, 'catalog.category_deleted', $before, [], 'Staf menghapus kategori yang tidak memiliki produk.');
            $current->delete();
        });
    }
    private function lockStaff(User $actor): User
    {
        $current = User::query()->whereKey($actor->id)->lockForUpdate()->first();
        if (! $current || ! $current->is_active || ! in_array($current->role, [UserRole::Admin, UserRole::Owner], true)) {
            throw new AuthorizationException('Hanya staf aktif yang dapat mengelola kategori.');
        }
        return $current;
    }
    private function audit(User $actor, Category $category, string $action, ?array $before, array $after, string $reason): void
    {
        $audit = new AuditLog;
        $audit->forceFill(['actor_user_id' => $actor->id, 'actor_type' => 'user', 'entity_type' => 'category', 'entity_id' => $category->id,
            'action' => $action, 'before_redacted' => $before, 'after_redacted' => $after, 'reason' => $reason, 'correlation_id' => (string) Str::uuid()])->save();
    }
}
