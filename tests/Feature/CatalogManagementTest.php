<?php

use App\CatalogManager;
use App\Models\AuditLog;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    if (config('database.default') !== 'mysql_testing') {
        $this->markTestSkipped('Catalog administration requires isolated MySQL.');
    }
    $this->withoutVite();
});

function catalogProductPayload(array $overrides = []): array
{
    return array_replace(['name' => 'Kue contoh', 'slug' => 'kue-contoh', 'category' => 'simple', 'description' => 'Deskripsi kue', 'sales_unit_label' => 'buah', 'is_active' => true, 'archived' => false], $overrides);
}

test('allows Admin and Owner catalog management and denies customers', function (UserRole $role, int $status) {
    $user = User::factory()->create(['role' => $role]);
    $this->actingAs($user)->get('/staff/products')->assertStatus($status);
    expect(Gate::forUser($user)->allows('create', Product::class))->toBe($status === 200);
})->with([[UserRole::Customer, 403], [UserRole::Admin, 200], [UserRole::Owner, 200]]);

test('denies guests inactive staff and unauthorized writes', function () {
    $this->get('/staff/products')->assertRedirect('/login');
    $this->actingAs(User::factory()->admin()->inactive()->create())->post('/staff/products', catalogProductPayload())->assertRedirect('/login');
    $this->actingAs(User::factory()->create())->postJson('/staff/products', catalogProductPayload())->assertForbidden();
    $this->assertDatabaseCount('products', 0);
    $this->assertDatabaseCount('audit_logs', 0);
});

test('creates products with safe fields and a redacted audit but no supplied quota or media', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin)->post('/staff/products', catalogProductPayload(['default_daily_quota' => 999, 'archived_at' => '2000-01-01', 'images' => [['path' => '../secret']]]))->assertRedirect()->assertSessionHasNoErrors();
    $product = Product::query()->sole();
    expect($product->default_daily_quota)->toBe(5);
    expect($product->archived_at)->toBeNull();
    expect($product->name)->toBe('Kue contoh');
    $this->assertDatabaseCount('product_images', 0);
    $audit = AuditLog::query()->sole();
    expect($audit->actor_user_id)->toBe($admin->id);
    expect($audit->action)->toBe('catalog.product_created');
    expect(json_encode($audit->after_redacted))->not->toContain('Deskripsi kue');
    $this->get('/staff/products/'.$product->id.'/edit')->assertOk()->assertSee('Kue contoh');
});

test('rejects invalid product inputs without a product or audit', function (array $invalid, string $field) {
    $this->actingAs(User::factory()->admin()->create())->postJson('/staff/products', catalogProductPayload($invalid))->assertUnprocessable()->assertJsonValidationErrors($field);
    $this->assertDatabaseCount('products', 0);
    $this->assertDatabaseCount('audit_logs', 0);
})->with([
    [['name' => ''], 'name'], [['name' => str_repeat('x', 151)], 'name'],
    [['slug' => '../unsafe'], 'slug'], [['slug' => str_repeat('a', 192)], 'slug'],
    [['category' => 'custom'], 'category'], [['sales_unit_label' => str_repeat('x', 33)], 'sales_unit_label'],
    [['is_active' => 'yes'], 'is_active'], [['archived' => []], 'archived'],
]);

test('rejects duplicate product slugs while allowing the unchanged slug on update', function () {
    $admin = User::factory()->admin()->create();
    $product = Product::factory()->create(['slug' => 'kue-contoh']);
    $this->actingAs($admin)->postJson('/staff/products', catalogProductPayload())->assertUnprocessable()->assertJsonValidationErrors('slug');
    $this->patch('/staff/products/'.$product->id, catalogProductPayload())->assertRedirect()->assertSessionHasNoErrors();
    $this->assertDatabaseCount('products', 1);
});

test('changes current prices and archives without rewriting historical snapshots', function () {
    $admin = User::factory()->admin()->create();
    $item = OrderItem::factory()->create();
    $before = $item->fresh()->getRawOriginal();
    $variant = $item->productVariant;
    $product = $item->product;
    $this->actingAs($admin)->patch('/staff/products/'.$product->id.'/variants/'.$variant->id, ['size_label' => 'Ukuran baru', 'price' => 200000, 'is_active' => true])->assertRedirect()->assertSessionHasNoErrors();
    expect($variant->fresh()->price)->toBe(200000);
    $this->get('/catalog/'.$product->slug)->assertOk()->assertSee('200.000');
    expect($item->fresh()->getRawOriginal())->toBe($before);
    $this->patch('/staff/products/'.$product->id, catalogProductPayload(['slug' => $product->slug, 'archived' => true]))->assertRedirect()->assertSessionHasNoErrors();
    expect($product->fresh()->is_active)->toBeFalse();
    expect($product->fresh()->archived_at)->not->toBeNull();
    expect($item->fresh()->getRawOriginal())->toBe($before);
    $this->get('/catalog/'.$product->slug)->assertNotFound();
    expect(AuditLog::query()->count())->toBe(2);
    $this->patch('/staff/products/'.$product->id, catalogProductPayload(['slug' => $product->slug, 'archived' => false]))->assertRedirect()->assertSessionHasNoErrors();
    $this->get('/catalog/'.$product->slug)->assertOk();
    expect($product->fresh()->archived_at)->toBeNull();
    expect($item->fresh()->getRawOriginal())->toBe($before);
    expect(Gate::forUser($admin)->allows('delete', $product))->toBeFalse();
});

test('creates and disables sizes only within their product', function () {
    $admin = User::factory()->admin()->create();
    $product = Product::factory()->create();
    $other = Product::factory()->create();
    $this->actingAs($admin)->post('/staff/products/'.$product->id.'/variants', ['size_label' => '20 cm', 'price' => 100000, 'is_active' => true, 'product_id' => $other->id])->assertRedirect()->assertSessionHasNoErrors();
    $variant = ProductVariant::query()->sole();
    expect($variant->product_id)->toBe($product->id);
    $this->patch('/staff/products/'.$other->id.'/variants/'.$variant->id, ['size_label' => '20 cm', 'price' => 100000, 'is_active' => false])->assertNotFound();
    expect($variant->fresh()->is_active)->toBeTrue();
    $this->patch('/staff/products/'.$product->id.'/variants/'.$variant->id, ['size_label' => '20 cm', 'price' => 100000, 'is_active' => false])->assertRedirect()->assertSessionHasNoErrors();
    expect($variant->fresh()->is_active)->toBeFalse();
    $this->get('/staff/products/'.$product->id.'/variants/'.$variant->id.'/edit')->assertOk();
});

test('validates integer positive prices and unique size labels per product', function (array $invalid, string $field) {
    $admin = User::factory()->admin()->create();
    $variant = ProductVariant::factory()->create(['size_label' => '20 cm']);
    $this->actingAs($admin)->postJson('/staff/products/'.$variant->product_id.'/variants', array_replace(['size_label' => '25 cm', 'price' => 100000, 'is_active' => true], $invalid))->assertUnprocessable()->assertJsonValidationErrors($field);
    $this->assertDatabaseCount('product_variants', 1);
    $this->assertDatabaseCount('audit_logs', 0);
})->with([
    [['price' => 0], 'price'], [['price' => -1], 'price'], [['price' => '12.5'], 'price'], [['price' => '9223372036854775808'], 'price'],
    [['size_label' => '20 cm'], 'size_label'], [['size_label' => str_repeat('x', 101)], 'size_label'],
]);

test('rolls back product writes when the required audit fails and rejects stale staff roles', function () {
    $admin = User::factory()->admin()->create();
    AuditLog::creating(fn () => throw new RuntimeException('Synthetic audit failure.'));
    try {
        expect(fn () => app(CatalogManager::class)->saveProduct($admin, catalogProductPayload()))->toThrow(RuntimeException::class);
        $this->assertDatabaseCount('products', 0);
    } finally {
        Event::forget('eloquent.creating: '.AuditLog::class);
    }
    User::query()->whereKey($admin->id)->update(['role' => 'customer']);
    expect(fn () => app(CatalogManager::class)->saveProduct($admin, catalogProductPayload()))->toThrow(AuthorizationException::class);
    $this->assertDatabaseCount('audit_logs', 0);
});

test('renders create forms and malformed flashed product input safely', function () {
    $admin = User::factory()->admin()->create();
    $product = Product::factory()->create(['name' => '<script>alert(1)</script>']);
    $this->actingAs($admin)->get('/staff/products/create')->assertOk()->assertSee('name="_token"', false);
    $this->get('/staff/products/'.$product->id.'/variants/create')->assertOk()->assertSee($product->name)->assertDontSee($product->name, false);
    $this->from('/staff/products/create')->post('/staff/products', catalogProductPayload(['description' => ['bad']]))->assertRedirect('/staff/products/create')->assertSessionHasErrors('description');
    $this->withCookie(config('session.cookie'), session()->getId())->get('/staff/products/create')->assertOk();
});

test('returns validation feedback on a synthetic product or size unique conflict with atomic rollback', function (string $kind) {
    $admin = User::factory()->admin()->create();
    $product = $kind === 'variant' ? Product::factory()->create() : null;
    $class = $kind === 'variant' ? ProductVariant::class : Product::class;
    $class::creating(function (Model $record): void {
        DB::table($record->getTable())->insert(array_replace($record->getAttributes(), ['created_at' => '2026-10-22 00:00:00', 'updated_at' => '2026-10-22 00:00:00']));
    });
    try {
        $url = $product ? '/staff/products/'.$product->id.'/variants' : '/staff/products';
        $payload = $product ? ['size_label' => '20 cm', 'price' => 100000, 'is_active' => true] : catalogProductPayload();
        $this->actingAs($admin)->postJson($url, $payload)->assertUnprocessable()->assertJsonValidationErrors($product ? 'size_label' : 'slug');
        $this->assertDatabaseCount('product_variants', 0);
        $this->assertDatabaseCount('products', $product ? 1 : 0);
        $this->assertDatabaseCount('audit_logs', 0);
    } finally {
        Event::forget('eloquent.creating: '.$class);
    }
})->with(['product', 'variant']);
