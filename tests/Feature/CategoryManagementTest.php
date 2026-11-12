<?php
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
uses(LazilyRefreshDatabase::class);
beforeEach(function () {
    if (config('database.default') !== 'mysql_testing') { $this->markTestSkipped('Category management requires isolated MySQL.'); }
    $this->withoutVite();
});
function categoryPayload(array $overrides = []): array
{
    return array_replace(['name' => 'Custom Cake', 'slug' => 'custom-cake', 'description' => 'Kategori custom', 'sort_order' => 40, 'is_active' => true], $overrides);
}
test('allows staff to create, view, update and delete empty categories', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin)->post('/staff/categories', categoryPayload())->assertRedirect(route('staff.categories.index'))->assertSessionHasNoErrors();
    $category = Category::query()->where('slug', 'custom-cake')->sole();
    $this->get(route('staff.categories.index'))->assertOk()->assertSee('Custom Cake');
    $this->put(route('staff.categories.update', $category), categoryPayload(['name' => 'Custom Celebration Cake', 'is_active' => false]))->assertRedirect()->assertSessionHasNoErrors();
    expect($category->fresh()->name)->toBe('Custom Celebration Cake')->and($category->fresh()->is_active)->toBeFalse();
    $this->delete(route('staff.categories.destroy', $category))->assertRedirect()->assertSessionHasNoErrors();
    $this->assertDatabaseMissing('categories', ['id' => $category->id]);
    expect(AuditLog::query()->where('entity_type', 'category')->count())->toBe(3);
});
test('blocks category deletion while products still reference it', function () {
    $admin = User::factory()->admin()->create();
    $category = Category::query()->where('slug', 'simple')->firstOrFail();
    $product = Product::factory()->create(['category_id' => $category->id]);
    $this->actingAs($admin)->from(route('staff.categories.index'))->delete(route('staff.categories.destroy', $category))->assertRedirect(route('staff.categories.index'))->assertSessionHasErrors('category');
    $this->assertModelExists($category);
    $this->assertModelExists($product);
    $this->assertDatabaseMissing('audit_logs', ['action' => 'catalog.category_deleted']);
});
test('denies customers and guests from category administration', function () {
    $this->get(route('staff.categories.index'))->assertRedirect('/login');
    $this->actingAs(User::factory()->create())->get(route('staff.categories.index'))->assertForbidden();
});
test('rejects duplicate slugs and invalid category values', function (array $payload, string $field) {
    $admin = User::factory()->admin()->create();
    Category::factory()->create(['slug' => 'already-used']);
    $this->actingAs($admin)->postJson(route('staff.categories.store'), categoryPayload($payload))->assertUnprocessable()->assertJsonValidationErrors($field);
    $this->assertDatabaseCount('audit_logs', 0);
})->with([
    'duplicate slug' => [['slug' => 'already-used'], 'slug'],
    'malformed slug' => [['slug' => '../bad'], 'slug'],
    'invalid order' => [['sort_order' => -1], 'sort_order'],
]);
